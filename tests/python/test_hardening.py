"""Fixes from the 1.0.0-rc1 review: malformed tokens, oversized or invalid output, snippets.

Each test reproduces one finding of the review; see docs/tasks/v1-rc1-review.md.
"""

from __future__ import annotations

import json
import linecache
import socket
import time
from typing import cast

from pyhp_worker import (
    HEADER,
    MAX_SNIPPETS,
    Connection,
    Session,
    _accept_php,
    _authenticated,
    listen,
)

TOKEN = "secret-token"  # noqa: S105  # throwaway handshake token for tests


def frame(message: dict[str, object] | bytes) -> bytes:
    body = message if isinstance(message, bytes) else json.dumps(message).encode()
    return HEADER.pack(len(body)) + body


def read_frame(sock: socket.socket) -> dict[str, object]:
    size = HEADER.unpack(sock.recv(HEADER.size, socket.MSG_WAITALL))[0]
    return cast("dict[str, object]", json.loads(sock.recv(size, socket.MSG_WAITALL)))


# -- finding 1: a malformed token crashed the worker before PHP connected ------ #


def test_a_token_with_an_unpaired_surrogate_is_refused_not_a_crash() -> None:
    worker_side, attacker = socket.socketpair()
    attacker.sendall(frame(b'{"op": "auth", "token": "\\ud800"}'))

    assert _authenticated(worker_side, TOKEN, 1.0) is False


def test_the_worker_keeps_waiting_for_php_after_a_malformed_token() -> None:
    server = listen()
    port = server.getsockname()[1]
    attacker = socket.create_connection(("127.0.0.1", port))
    attacker.sendall(frame(b'{"op": "auth", "token": "\\udfff"}'))
    php = socket.create_connection(("127.0.0.1", port))
    php.sendall(frame({"op": "auth", "token": TOKEN}))

    accepted = _accept_php(server, TOKEN, deadline=time.monotonic() + 10)

    assert accepted is not None
    accepted.close()
    php.close()
    attacker.close()
    server.close()


# -- finding 3: output could end a healthy session ------------------------------ #


def test_output_too_large_for_the_frame_is_cut_and_the_call_still_succeeds() -> None:
    worker_side, php = socket.socketpair()
    output = "".join(f"line {i}\n" for i in range(300))

    Connection(worker_side, 1024).send({"ok": True, "value": 42, "tagged": False, "out": output})

    size = HEADER.unpack(php.recv(HEADER.size, socket.MSG_WAITALL))[0]
    response = json.loads(php.recv(size, socket.MSG_WAITALL))
    out = str(response["out"])
    assert size <= 1024
    assert response["ok"] is True
    assert response["value"] == 42
    assert out.startswith("[pyhp: the first ")
    assert "characters of output were cut to fit max_message_bytes]\n" in out
    assert out.endswith("line 299\n")
    cut = int(out.split()[3])
    assert len(out) - out.index("\n") - 1 + cut == len(output)


def test_an_error_response_with_large_output_still_fits() -> None:
    session = Session()
    response = session.handle({"op": "exec", "code": "print('A' * 1100)\nraise ValueError('boom')"})
    worker_side, php = socket.socketpair()

    Connection(worker_side, 1024).send(response)

    error = read_frame(php)
    details = cast("dict[str, object]", error["error"])
    assert error["ok"] is False
    assert details["message"] == "boom"
    assert str(error["out"]).endswith("A" * 100 + "\n")


def test_an_error_with_a_huge_message_and_traceback_still_fits() -> None:
    session = Session()
    response = session.handle({"op": "exec", "code": "raise ValueError('x' * 5000)"})
    worker_side, php = socket.socketpair()

    Connection(worker_side, 1024).send(response)

    size = HEADER.unpack(php.recv(HEADER.size, socket.MSG_WAITALL))[0]
    error = json.loads(php.recv(size, socket.MSG_WAITALL))
    assert size <= 1024
    assert error["error"]["type"] == "ValueError"
    assert error["error"]["message"].startswith("x" * 100)
    assert error["error"]["message"].endswith("[pyhp: cut to fit max_message_bytes]")


def test_text_with_unpaired_surrogates_is_sent_with_replacement_characters() -> None:
    session = Session()
    response = session.handle(
        {"op": "exec", "code": "print('a\\ud800b')\nraise ValueError('\\udfff')"}
    )
    worker_side, php = socket.socketpair()

    Connection(worker_side, 10_000).send(response)

    error = read_frame(php)
    assert error["out"] == "a?b\n"
    assert cast("dict[str, object]", error["error"])["message"] == "?"


def test_live_output_comes_in_frames_that_fit_the_limit() -> None:
    sent: list[dict[str, object]] = []

    class SmallPhp:
        max_frame = 1024

        def send(self, message: dict[str, object]) -> None:
            sent.append(message)

        def send_call(self, message: dict[str, object]) -> None:
            body = json.dumps(message, ensure_ascii=False).encode()
            assert len(body) <= self.max_frame, len(body)
            sent.append(message)

        def receive(self) -> None:
            return None

    session = Session(bridge=SmallPhp())
    response = session.handle({"op": "exec", "code": "print('\\u00e9\\n' * 3000)", "stream": True})

    texts = [str(m["text"]) for m in sent if m.get("op") == "output"]
    assert response["ok"] is True
    assert "".join(texts) + str(response["out"]) == "é\n" * 3000 + "\n"


# -- finding 4: inline source piled up in linecache ------------------------------ #


def test_inline_source_is_kept_for_the_most_recent_snippets_only() -> None:
    session = Session()
    before = {name for name in linecache.cache if str(name).startswith("<pyhp-")}

    for _ in range(MAX_SNIPPETS + 50):
        session.handle({"op": "eval", "expr": "1 + 1"})
    response = session.handle({"op": "exec", "code": "x = 1\nraise RuntimeError('recent')"})

    kept = {name for name in linecache.cache if str(name).startswith("<pyhp-")} - before
    traceback = str(cast("dict[str, object]", response["error"])["traceback"])
    assert len(kept) == MAX_SNIPPETS
    assert "raise RuntimeError('recent')" in traceback
