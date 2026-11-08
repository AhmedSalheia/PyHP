"""Live output: what Python prints reaches PHP while a call is still running."""

from __future__ import annotations

import threading

from pyhp_worker import OUTPUT_CHUNK, Session

Message = dict[str, object]


class FakePhp:
    def __init__(self) -> None:
        self.sent: list[Message] = []

    def send(self, message: Message) -> None:
        self.sent.append(message)

    def send_call(self, message: Message) -> None:
        self.sent.append(message)

    def receive(self) -> Message | None:
        return None


def make() -> tuple[Session, FakePhp]:
    php = FakePhp()
    return Session(bridge=php), php


def frames(php: FakePhp) -> list[str]:
    return [str(m["text"]) for m in php.sent if m.get("op") == "output"]


def test_streamed_lines_are_sent_while_the_call_runs() -> None:
    session, php = make()

    response = session.handle(
        {"op": "exec", "code": "print('first')\nprint('second', end='')", "stream": True}
    )

    assert frames(php) == ["first\n"]
    assert response["ok"] is True
    assert response["out"] == "second"  # text without a newline yet arrives with the response


def test_without_stream_everything_arrives_with_the_response() -> None:
    session, php = make()

    response = session.handle({"op": "exec", "code": "print('a')\nprint('b')"})

    assert frames(php) == []
    assert response["out"] == "a\nb\n"


def test_explicit_flush_sends_partial_lines() -> None:
    session, php = make()

    session.handle(
        {"op": "exec", "code": "import sys\nprint('50%', end='', flush=True)", "stream": True}
    )

    assert frames(php) == ["50%"]


def test_huge_output_is_split_into_chunks() -> None:
    session, php = make()

    session.handle({"op": "exec", "code": f"print('x' * {OUTPUT_CHUNK * 2 + 10})", "stream": True})

    sent = frames(php)
    assert len(sent) == 3
    assert all(len(text) <= OUTPUT_CHUNK for text in sent)
    assert "".join(sent) == "x" * (OUTPUT_CHUNK * 2 + 10) + "\n"


def test_output_of_a_failing_call_is_still_delivered() -> None:
    session, php = make()

    response = session.handle(
        {"op": "exec", "code": "print('before')\nprint('tail', end='')\n1/0", "stream": True}
    )

    assert frames(php) == ["before\n"]
    assert response["ok"] is False
    assert response["out"] == "tail"


def test_background_threads_never_write_to_the_socket() -> None:
    session, php = make()
    seen: list[int] = []
    original = php.send_call

    def record(message: Message) -> None:
        seen.append(threading.get_ident())
        original(message)

    php.send_call = record  # type: ignore[method-assign]  # spy on which thread sends
    code = (
        "import threading\n"
        "t = threading.Thread(target=lambda: print('from a thread'))\n"
        "t.start(); t.join()\n"
        "print('from the main thread')\n"
    )

    session.handle({"op": "exec", "code": code, "stream": True})

    assert set(seen) == {threading.get_ident()}
    assert "".join(frames(php)) == "from a thread\nfrom the main thread\n"
