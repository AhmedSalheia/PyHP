"""A misbehaving PyHP worker, for tests of how PHP handles start-up failures.

Python imports `sitecustomize` before it runs the worker script, so a test that puts
this folder on PYTHONPATH (with the `env` option) and sets PYHP_FAKE_WORKER makes
the process act out one failure instead of starting the real worker:

  exit:<code>:<text>   write <text> to stderr and exit with <code>
  log:<n>              write <n> characters (A..., then B... for the last half) and exit 3
  hang                 never write the port (start-up timeout); the pid goes to PYHP_FAKE_PID_FILE
  hello:<json>         answer the handshake with this JSON (the text TOKEN becomes the real token)
  raw:<hex>            answer the handshake with these bytes
  bad-frame            a correct handshake, then announce a frame too large to accept
  reply:<hex>          a correct handshake, then answer the first request with these bytes

Without PYHP_FAKE_WORKER, it does nothing.
"""

from __future__ import annotations

import json
import os
import socket
import struct
import sys
import time

MODE = os.environ.get("PYHP_FAKE_WORKER", "")
TOKEN = os.environ.get("PYHP_TOKEN", "")


def _serve(answer: bytes, then: bytes = b"") -> None:
    with socket.create_server(("127.0.0.1", 0)) as server:
        with open(os.environ["PYHP_PORT_FILE"], "w", encoding="ascii") as port_file:
            port_file.write(f"{server.getsockname()[1]}\n")
        conn, _ = server.accept()
    with conn:
        (length,) = struct.unpack(">I", conn.recv(4))
        conn.recv(length)  # PHP's auth frame
        conn.sendall(answer)
        if then:
            conn.recv(4)
            conn.sendall(then)
        time.sleep(5)  # PHP decides what happens next


def _frame(message: dict[str, object]) -> bytes:
    body = json.dumps(message).encode()
    return struct.pack(">I", len(body)) + body


if MODE:
    kind, _, argument = MODE.partition(":")
    if kind == "exit":
        code, _, text = argument.partition(":")
        sys.stderr.write(text)
        sys.stderr.flush()
        os._exit(int(code))
    if kind == "log":
        half = int(argument) // 2
        sys.stderr.write("A" * half + "B" * half)
        sys.stderr.flush()
        os._exit(3)
    if kind == "hang":
        with open(os.environ["PYHP_FAKE_PID_FILE"], "w", encoding="ascii") as pid_file:
            pid_file.write(str(os.getpid()))
        time.sleep(30)
        os._exit(0)
    if kind == "hello":
        body = argument.replace("TOKEN", TOKEN).encode()
        _serve(struct.pack(">I", len(body)) + body)
        os._exit(0)
    if kind == "raw":
        _serve(bytes.fromhex(argument))
        os._exit(0)
    if kind in ("bad-frame", "reply"):
        hello = _frame({"op": "hello", "token": TOKEN, "protocol": 4, "python": "3.13.0", "pid": 1})
        _serve(
            hello, then=struct.pack(">I", 2**31) if kind == "bad-frame" else bytes.fromhex(argument)
        )
        os._exit(0)
