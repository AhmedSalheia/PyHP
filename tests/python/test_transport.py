"""End-to-end: start the worker as a real process and talk to it over the socket."""

from __future__ import annotations

import json
import os
import socket
import struct
import subprocess
import sys
import time
from collections.abc import Iterator
from dataclasses import dataclass
from pathlib import Path

import pytest

WORKER = Path(__file__).resolve().parents[2] / "python" / "pyhp_worker.py"
HEADER = struct.Struct(">I")
TOKEN = "test-token"  # noqa: S105  # throwaway handshake token for tests


@dataclass
class Connection:
    sock: socket.socket
    process: subprocess.Popen[bytes]
    log: Path

    def send(self, message: dict[str, object]) -> None:
        body = json.dumps(message).encode()
        self.sock.sendall(HEADER.pack(len(body)) + body)

    def receive(self) -> dict[str, object]:
        (length,) = HEADER.unpack(self._exact(HEADER.size))
        result = json.loads(self._exact(length))
        assert isinstance(result, dict)
        return result

    def _exact(self, size: int) -> bytes:
        chunks = b""
        while len(chunks) < size:
            chunk = self.sock.recv(size - len(chunks))
            assert chunk, "worker closed the connection"
            chunks += chunk
        return chunks


def frame(message: dict[str, object]) -> bytes:
    body = json.dumps(message).encode()
    return HEADER.pack(len(body)) + body


def launch(
    tmp_path: Path, token: str = TOKEN, max_frame: int = 1_000_000, startup_timeout: float = 10
) -> tuple[subprocess.Popen[bytes], int, Path]:
    """Start the worker; return the process, the port it listens on, and its log."""
    port_file = tmp_path / "port"
    port_file.write_text("")
    log = tmp_path / "worker.log"
    env = dict(
        os.environ,
        PYHP_PORT_FILE=str(port_file),
        PYHP_TOKEN=token,
        PYHP_MAX_FRAME=str(max_frame),
        PYHP_STARTUP_TIMEOUT=str(startup_timeout),
    )
    with log.open("wb") as log_file:
        process = subprocess.Popen(  # noqa: S603  # fixed argv, no shell
            [sys.executable, "-u", str(WORKER)],
            stdin=subprocess.DEVNULL,
            stdout=log_file,
            stderr=log_file,
            env=env,
        )
    deadline = time.monotonic() + 10
    while not port_file.read_text().endswith("\n"):
        assert process.poll() is None, log.read_text()
        assert time.monotonic() < deadline, "worker never published its port"
        time.sleep(0.005)
    return process, int(port_file.read_text()), log


def start_worker(tmp_path: Path, token: str = TOKEN, max_frame: int = 1_000_000) -> Connection:
    process, port, log = launch(tmp_path, token, max_frame)
    sock = socket.create_connection(("127.0.0.1", port), timeout=10)
    sock.sendall(frame({"op": "auth", "token": token}))
    return Connection(sock, process, log)


@pytest.fixture
def worker(tmp_path: Path) -> Iterator[Connection]:
    connection = start_worker(tmp_path)
    yield connection
    connection.sock.close()
    connection.process.wait(timeout=10)


def test_worker_sends_hello_with_token_first(worker: Connection) -> None:
    hello = worker.receive()
    assert hello["op"] == "hello"
    assert hello["token"] == TOKEN
    assert hello["protocol"] == 1


def test_worker_answers_requests_and_keeps_state(worker: Connection) -> None:
    worker.receive()
    worker.send({"op": "exec", "code": "x = 2"})
    assert worker.receive()["ok"] is True
    worker.send({"op": "eval", "expr": "x * 21"})
    assert worker.receive()["value"] == 42


def test_user_output_never_reaches_the_protocol_channel(worker: Connection) -> None:
    worker.receive()
    code = (
        "import os, sys\n"
        "os.write(1, b'raw-fd-1\\n')\n"
        "print('captured')\n"
        "print('err', file=sys.stderr)"
    )
    worker.send({"op": "exec", "code": code})
    response = worker.receive()
    assert response["ok"] is True
    assert response["out"] == "captured\n"
    worker.send({"op": "ping"})
    assert worker.receive()["ok"] is True
    worker.sock.close()
    worker.process.wait(timeout=10)
    log_text = worker.log.read_text()
    assert "raw-fd-1" in log_text
    assert "err" in log_text


def test_worker_control_variables_are_removed_from_user_code(worker: Connection) -> None:
    # The user's own environment (even variables starting with PYHP_) passes through;
    # only the worker's control variables, including the secret token, are removed.
    worker.receive()
    names = ["PYHP_PORT", "PYHP_TOKEN", "PYHP_MAX_FRAME", "PYHP_PATHS"]
    worker.send({"op": "eval", "expr": f"[k for k in {names!r} if k in __import__('os').environ]"})
    assert worker.receive()["value"] == []
    worker.send({"op": "eval", "expr": f"{TOKEN!r} in __import__('os').environ.values()"})
    assert worker.receive()["value"] is False


def test_input_reads_nothing(worker: Connection) -> None:
    worker.receive()
    worker.send({"op": "exec", "code": "input()"})
    error = worker.receive()["error"]
    assert isinstance(error, dict)
    assert error["type"] == "EOFError"


def test_oversized_responses_are_replaced_by_an_error(tmp_path: Path) -> None:
    connection = start_worker(tmp_path, max_frame=1000)
    connection.receive()
    connection.send({"op": "eval", "expr": "'x' * 5000"})
    response = connection.receive()
    error = response["error"]
    assert isinstance(error, dict)
    assert error["kind"] == "too_large"
    connection.send({"op": "eval", "expr": "1"})
    assert connection.receive()["value"] == 1
    connection.sock.close()
    connection.process.wait(timeout=10)


def test_shutdown_request_stops_the_worker(worker: Connection) -> None:
    worker.receive()
    worker.send({"op": "shutdown"})
    assert worker.receive()["ok"] is True
    assert worker.process.wait(timeout=10) == 0


def test_closing_the_connection_stops_the_worker(worker: Connection) -> None:
    worker.receive()
    worker.sock.close()
    assert worker.process.wait(timeout=10) == 0


def test_oversized_requests_stop_the_worker(tmp_path: Path) -> None:
    connection = start_worker(tmp_path, max_frame=100)
    connection.receive()
    connection.sock.sendall(HEADER.pack(5000))
    assert connection.process.wait(timeout=10) == 2
    connection.sock.close()


def test_non_object_requests_stop_the_worker(worker: Connection) -> None:
    worker.receive()
    body = b"[1, 2]"
    worker.sock.sendall(HEADER.pack(len(body)) + body)
    assert worker.process.wait(timeout=10) == 2


def test_extra_paths_are_added_to_sys_path(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    library = tmp_path / "lib"
    library.mkdir()
    (library / "pyhp_extra_module.py").write_text("VALUE = 7\n", encoding="utf-8")
    monkeypatch.setenv("PYHP_PATHS", str(library))
    connection = start_worker(tmp_path)
    connection.receive()
    connection.send({"op": "eval", "expr": "__import__('pyhp_extra_module').VALUE"})
    assert connection.receive()["value"] == 7
    connection.sock.close()
    connection.process.wait(timeout=10)


def test_connections_without_the_token_are_dropped(tmp_path: Path) -> None:
    process, port, log = launch(tmp_path)
    with socket.create_connection(("127.0.0.1", port), timeout=10) as rogue:
        rogue.sendall(frame({"op": "auth", "token": "wrong"}))
        assert rogue.recv(1) == b""  # closed without a word
    with socket.create_connection(("127.0.0.1", port), timeout=10) as garbage:
        garbage.sendall(HEADER.pack(10_000_000))
        assert garbage.recv(1) == b""
    connection = Connection(socket.create_connection(("127.0.0.1", port), timeout=10), process, log)
    connection.sock.sendall(frame({"op": "auth", "token": TOKEN}))
    assert connection.receive()["op"] == "hello"
    connection.sock.close()
    assert process.wait(timeout=10) == 0


def test_worker_gives_up_when_nobody_connects(tmp_path: Path) -> None:
    process, _, log = launch(tmp_path, startup_timeout=0.5)
    assert process.wait(timeout=10) == 3
    assert "did not connect" in log.read_text()
