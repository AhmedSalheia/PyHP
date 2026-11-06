"""End-to-end: start the worker as a real process and talk to it over the socket."""

from __future__ import annotations

import json
import os
import socket
import struct
import subprocess
import sys
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


def start_worker(tmp_path: Path, token: str = TOKEN, max_frame: int = 1_000_000) -> Connection:
    server = socket.create_server(("127.0.0.1", 0))
    server.settimeout(10)
    port = server.getsockname()[1]
    log = tmp_path / "worker.log"
    env = dict(os.environ, PYHP_PORT=str(port), PYHP_TOKEN=token, PYHP_MAX_FRAME=str(max_frame))
    with log.open("wb") as log_file:
        process = subprocess.Popen(  # noqa: S603  # fixed argv, no shell
            [sys.executable, "-u", str(WORKER)],
            stdin=subprocess.DEVNULL,
            stdout=log_file,
            stderr=log_file,
            env=env,
        )
    sock, _ = server.accept()
    server.close()
    sock.settimeout(10)
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


def test_secret_environment_is_removed_from_user_code(worker: Connection) -> None:
    worker.receive()
    worker.send(
        {"op": "eval", "expr": "[k for k in __import__('os').environ if k.startswith('PYHP_')]"}
    )
    assert worker.receive()["value"] == []


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
