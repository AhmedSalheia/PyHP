"""Every error message the worker writes, word for word.

PHP shows these to developers, so they're part of what the worker does. Other tests
check that the right error happens; these pin its exact text, so a message that
changes (or loses its useful part) doesn't go unnoticed.
"""

from __future__ import annotations

import json
import socket
import sys
import threading
from pathlib import Path
from typing import cast

import pytest

import pyhp_worker
from pyhp_worker import (
    MAX_DEPTH,
    MIN_PYTHON,
    Connection,
    ConversionError,
    FatalFrameError,
    PhpCallable,
    Registry,
    Session,
    decode,
    encode,
    unsupported_python,
)
from test_transport import HEADER, launch, start_worker

Message = dict[str, object]
CALLBACK = {"$t": "callback", "id": 7}


class FakePhp:
    def __init__(self, script: list[Message] | None = None) -> None:
        self.sent: list[Message] = []
        self._script = list(script or [])

    def send(self, message: Message) -> None:
        self.sent.append(message)

    def send_call(self, message: Message) -> None:
        self.sent.append(message)

    def receive(self) -> Message | None:
        return self._script.pop(0) if self._script else None


def message_of(response: Message) -> str:
    assert response["ok"] is False, response
    return str(cast("dict[str, object]", response["error"])["message"])


def value_of(response: Message) -> object:
    assert response["ok"] is True, response
    return response["value"]


@pytest.mark.parametrize(
    ("request_", "message"),
    [
        ({"op": "nope"}, "unknown operation 'nope'"),
        ({"op": "get_var"}, "field 'name' must be a string"),
        ({"op": "get_var", "name": ""}, "field 'name' must not be empty"),
        ({"op": "get_var", "name": "missing_x"}, "name 'missing_x' is not defined"),
        (
            {"op": "global_names", "kind": "bad"},
            "field 'kind' must be one of any, module, class, callable",
        ),
        ({"op": "call_global", "name": "len", "kwargs": []}, "kwargs must be an object"),
        ({"op": "call_global", "name": "len", "args": 5}, "args must be a list"),
        ({"op": "call_global", "name": "number"}, "'number' is a int, not a function"),
        (
            {"op": "get_global", "name": "number", "kind": "class"},
            "'number' is a int, not a class",
        ),
        ({"op": "set_var", "name": "a"}, "missing field 'value'"),
        (
            {"op": "import", "module": "json", "as": "1bad"},
            "import alias must be a Python identifier",
        ),
        ({"op": "import", "module": "*"}, "'*' can only be imported from a module (use from=...)"),
        ({"op": "import", "module": "x", "from": ""}, "field 'from' must be a module name"),
        (
            {"op": "import", "module": "*", "from": "json", "as": "j"},
            "'from ... import *' cannot have an alias",
        ),
        (
            {"op": "import", "module": "nope", "from": "json"},
            "cannot import name 'nope' from 'json'",
        ),
        ({"op": "ping", "release": "no"}, "release must be a list"),
        ({"op": "ping", "release": ["a"]}, "release id must be an integer"),
        (
            {"op": "getattr", "ref": 999, "name": "x"},
            "Python object #999 no longer exists in this session",
        ),
        ({"op": "getattr", "ref": "a", "name": "x"}, "ref must be an integer"),
        ({"op": "next_batch", "ref": "a", "size": 1}, "ref must be an integer"),
    ],
)
def test_malformed_requests(request_: Message, message: str) -> None:
    session = Session(bridge=FakePhp())
    session.handle({"op": "exec", "code": "number = 1"})

    assert message_of(session.handle(request_)) == message


@pytest.mark.parametrize(
    ("value", "message"),
    [
        ({"$t": "bytes", "v": 1}, "bytes value must be a base64 string"),
        ({"$t": "float", "v": "x"}, "invalid special float 'x'"),
        ({"$t": "weird"}, "unknown value tag 'weird'"),
        ({"$t": "dict", "v": 5}, "dict pairs must be a list"),
        ({"$t": "dict", "v": [5]}, "dict pair must be a list"),
        ({"$t": "tuple", "v": 5}, "tuple must be a list"),
        ({"$t": "slice", "v": 5}, "slice must be a list"),
        ({"$t": "ref", "id": "a"}, "ref id must be an integer"),
        ({"$t": "callback", "id": "a"}, "callback id must be an integer"),
        ({"$t": "vectorize", "fn": CALLBACK, "batch": "a"}, "batch size must be an integer"),
        (
            {"$t": "vectorize", "fn": CALLBACK, "batch": 0},
            "Py::vectorize() needs a PHP closure and a batch size of at least 1",
        ),
    ],
)
def test_values_that_cannot_be_decoded(value: Message, message: str) -> None:
    session = Session(bridge=FakePhp())

    assert message_of(session.handle({"op": "set_var", "name": "v", "value": value})) == message


def test_decoding_outside_a_session() -> None:
    with pytest.raises(ConversionError) as no_session:
        decode(CALLBACK, Registry())
    with pytest.raises(ConversionError) as not_json:
        decode(object(), Registry())

    assert str(no_session.value) == "PHP callbacks can only be used inside a session"
    assert str(not_json.value) == "unexpected wire value of type object"


def test_values_that_cannot_be_copied_into_php() -> None:
    cycle: list[object] = []
    cycle.append(cycle)
    deep: list[object] = []
    current = deep
    for _ in range(MAX_DEPTH + 1):
        child: list[object] = []
        current.append(child)
        current = child

    messages = []
    for value in ({1: "a", "1": "b"}, cycle, deep, object()):
        with pytest.raises(ConversionError) as error:
            encode(value, Registry(), strict=True)
        messages.append(str(error.value))

    assert messages == [
        "cannot convert dict key '1' to a distinct PHP array key",
        "cannot convert list containing a reference cycle",
        "cannot convert list containing nesting deeper than 256",
        "cannot convert a object to a PHP value",
    ]


def test_operations_on_the_wrong_kind_of_object() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "import types\nthing = types.SimpleNamespace(x=1)"})
    session.handle({"op": "exec", "code": "plain = object()\nitems = [1]\nit = iter([1])"})
    refs = {
        name: cast(
            "dict[str, object]", value_of(session.handle({"op": "get_var_ref", "name": name}))
        )["id"]
        for name in ("thing", "plain", "items", "it")
    }

    assert message_of(session.handle({"op": "call_method", "ref": refs["thing"], "name": "x"})) == (
        "attribute 'x' is a int, not a method"
    )
    assert message_of(session.handle({"op": "call", "ref": refs["plain"]})) == (
        "a object object is not callable"
    )
    assert message_of(session.handle({"op": "next_batch", "ref": refs["plain"], "size": 1})) == (
        "next_batch needs an iterator reference"
    )
    assert message_of(session.handle({"op": "next_batch", "ref": refs["it"], "size": "a"})) == (
        "size must be an integer"
    )
    assert message_of(session.handle({"op": "table", "ref": refs["items"]})) == (
        "a pandas DataFrame is needed, not a list"
    )


def test_tables_php_cannot_hold() -> None:
    pytest.importorskip("pandas")
    session = Session()
    session.handle(
        {
            "op": "exec",
            "code": (
                "import decimal, pandas as pd\n"
                "money = pd.DataFrame({'price': [decimal.Decimal('1.5')]})\n"
                "clash = pd.DataFrame([[1, 2]], columns=[1, '1'])"
            ),
        }
    )

    def table_message(name: str) -> str:
        ref = cast("dict[str, object]", value_of(session.handle({"op": "get_var", "name": name})))
        return message_of(session.handle({"op": "table", "ref": ref["id"]}))

    assert table_message("money") == (
        "column 'price' holds decimal.Decimal values, which have no PHP equivalent; "
        "convert the column first, e.g. df['price'].astype(str)"
    )
    assert table_message("clash") == (
        "column name '1' can't be a distinct PHP array key; "
        "rename the columns first (e.g. df.columns = [str(c) for c in df.columns])"
    )


# -- callbacks ---------------------------------------------------------------- #


def run_callback(session: Session) -> str:
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})
    return message_of(session.handle({"op": "call_global", "name": "run", "args": [CALLBACK]}))


def test_callback_errors() -> None:
    assert run_callback(Session()) == "this session has no PHP connection to call back into"

    deep = Session(bridge=FakePhp())
    deep.callback_depth = 32
    assert run_callback(deep) == "PHP callbacks are nested more than 32 levels deep"

    assert run_callback(Session(bridge=FakePhp([{"op": "php_return", "seq": 99, "ok": True}]))) == (
        "PHP answered callback call 99 while call 1 was waiting"
    )
    assert run_callback(Session(bridge=FakePhp([{"op": "php_return", "seq": 1, "ok": False}]))) == (
        "PHP reported a failed callback without details"
    )


def test_callbacks_outside_a_call_or_from_another_thread() -> None:
    session = Session(bridge=FakePhp())
    callback = session._callback_for(7)
    with pytest.raises(RuntimeError) as outside:
        callback()

    errors: list[str] = []

    def other_thread() -> None:
        try:
            callback()
        except RuntimeError as error:
            errors.append(str(error))

    thread = threading.Thread(target=other_thread)
    thread.start()
    thread.join()

    assert str(outside.value) == (
        "PHP callbacks can only be called while PHP is waiting on a call into Python"
    )
    assert errors == [
        "PHP callbacks can only be called from the thread that serves PHP's requests, "
        "not from other threads"
    ]
    assert isinstance(callback, PhpCallable)


@pytest.mark.parametrize(
    ("answer", "message"),
    [
        (
            {"$t": "dict", "v": [["a", 1]]},
            "a vectorized PHP callback must return an array with one value per input value "
            "(a list: use array_values() after filtering), got dict",
        ),
        ([1], "the vectorized PHP callback returned 1 values for a chunk of 2"),
    ],
)
def test_vectorized_callbacks_that_answer_wrongly(answer: object, message: str) -> None:
    php = FakePhp([{"op": "php_return", "seq": 1, "ok": True, "value": answer}])
    session = Session(bridge=php)
    session.handle(
        {"op": "set_var", "name": "f", "value": {"$t": "vectorize", "fn": CALLBACK, "batch": 2}}
    )

    assert message_of(session.handle({"op": "eval", "expr": "f([1, 2])"})) == message
    assert value_of(session.handle({"op": "eval", "expr": "repr(f)"})) == (
        "<vectorized PHP callback 7, batch 2>"
    )


# -- frames ----------------------------------------------------------------------- #


def test_frame_errors() -> None:
    worker_side, php = socket.socketpair()
    connection = Connection(worker_side, 10)
    php.sendall((11).to_bytes(4, "big") + b"x" * 11)
    with pytest.raises(FatalFrameError) as too_long:
        connection.receive()

    messages = []
    for body in (b"{nope", b"[1]"):
        worker_side, php = socket.socketpair()
        php.sendall(len(body).to_bytes(4, "big") + body)
        with pytest.raises(FatalFrameError) as error:
            Connection(worker_side, 100).receive()
        messages.append(str(error.value))

    assert str(too_long.value) == "request of 11 bytes exceeds the limit"
    assert messages == ["request is not valid JSON", "request is not a JSON object"]


def test_frames_above_the_limit() -> None:
    worker_side, php = socket.socketpair()
    Connection(worker_side, 100).send({"ok": True, "value": "x" * 200, "out": ""})
    size = int.from_bytes(php.recv(4), "big")
    response = json.loads(php.recv(size))

    with pytest.raises(ConversionError) as call:
        Connection(socket.socketpair()[0], 10).send_call({"op": "php_call", "args": ["x" * 20]})

    assert response["error"]["message"] == (
        "the response is 236 bytes, above the 100-byte limit; "
        "keep the value in Python (use a reference) or raise max_message_bytes"
    )
    assert response["error"]["kind"] == "too_large"
    assert str(call.value) == (
        "the arguments of a PHP callback are 52 bytes, above the 10-byte limit; "
        "pass a reference instead"
    )


# -- the worker process ---------------------------------------------------------- #


def test_start_up_messages_in_the_log(tmp_path: Path) -> None:
    process, _, log = launch(tmp_path, startup_timeout=0.5)
    process.wait(timeout=10)

    assert log.read_text() == "pyhp: PHP did not connect within the start-up timeout, stopping\n"


def test_the_old_python_message(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setattr(sys, "version_info", (3, 10, 19, "final", 0))

    pyhp_worker.main()

    assert (
        capsys.readouterr().err
        == "pyhp: PyHP needs Python 3.11 or newer, but this is Python 3.10.19\n"
    )


def test_a_fatal_frame_is_logged_before_the_worker_stops(tmp_path: Path) -> None:
    worker = start_worker(tmp_path)
    worker.receive()
    body = b"[1, 2]"
    worker.sock.sendall(HEADER.pack(len(body)) + body)
    worker.process.wait(timeout=10)
    worker.sock.close()

    assert worker.log.read_text() == "pyhp: request is not a JSON object, stopping\n"


def test_the_oldest_supported_python_is_accepted_and_the_one_before_is_not() -> None:
    oldest = (*MIN_PYTHON, 0)
    before = (MIN_PYTHON[0], MIN_PYTHON[1] - 1, 9)

    assert unsupported_python(oldest) is None
    assert (
        unsupported_python(before) == "PyHP needs Python 3.11 or newer, but this is Python 3.10.9"
    )
