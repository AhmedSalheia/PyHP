"""Exact limits, counters and error messages: the edges mutation testing found unchecked."""

from __future__ import annotations

import json
import numbers
import platform
import socket
from typing import cast

import pytest

from pyhp_worker import (
    DEFAULT_MAX_FRAME,
    INT64_MAX,
    INT64_MIN,
    MAX_AUTH_FRAME,
    MAX_DEPTH,
    OUTPUT_CHUNK,
    Connection,
    ConversionError,
    FatalFrameError,
    Registry,
    Session,
    _authenticated,
    decode,
    encode_value,
)

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


def error_of(response: Message) -> dict[str, object]:
    assert response["ok"] is False, response
    return cast("dict[str, object]", response["error"])


def ok_value(response: Message) -> object:
    assert response["ok"] is True, response
    return response["value"]


# -- constants that are part of the contract with PHP ----------------------- #


def test_the_limits_are_the_documented_ones() -> None:
    assert (INT64_MIN, INT64_MAX) == (-(2**63), 2**63 - 1)
    assert MAX_DEPTH == 256
    assert OUTPUT_CHUNK == 16 * 1024
    assert DEFAULT_MAX_FRAME == 64 * 1024 * 1024
    assert MAX_AUTH_FRAME == 4096


# -- 64-bit integers --------------------------------------------------------- #


@pytest.mark.parametrize("value", [INT64_MIN, INT64_MAX])
def test_the_extreme_64_bit_integers_are_plain_even_in_strict_mode(value: int) -> None:
    assert encode_value(value, Registry(), strict=True) == (value, False)


@pytest.mark.parametrize("value", [INT64_MIN - 1, INT64_MAX + 1])
def test_integers_just_outside_64_bits_are_never_plain(value: int) -> None:
    with pytest.raises(ConversionError):
        encode_value(value, Registry(), strict=True)


class Whole:
    """A whole number that isn't a Python int, like sympy.Integer."""

    def __init__(self, value: int) -> None:
        self.value = value

    def __index__(self) -> int:
        return self.value


numbers.Integral.register(Whole)


@pytest.mark.parametrize("value", [INT64_MIN, INT64_MAX])
def test_whole_numbers_at_the_64_bit_limits_become_ints(value: int) -> None:
    assert encode_value(Whole(value), Registry()) == (value, False)


@pytest.mark.parametrize("value", [INT64_MIN - 1, INT64_MAX + 1])
def test_whole_numbers_outside_64_bits_stay_references(value: int) -> None:
    encoded, tagged = encode_value(Whole(value), Registry())
    assert tagged is True
    assert cast("dict[str, object]", encoded)["$t"] == "ref"


@pytest.mark.parametrize("key", [INT64_MIN, INT64_MAX])
def test_dicts_with_extreme_64_bit_keys_are_plain(key: int) -> None:
    encoded, tagged = encode_value({key: "x"}, Registry())
    assert (encoded, tagged) == ({str(key): "x"}, False)


@pytest.mark.parametrize("key", [INT64_MIN, INT64_MAX])
def test_a_numeric_string_key_and_the_same_int_key_collide_like_in_php(key: int) -> None:
    encoded, tagged = encode_value({key: "int", str(key): "str"}, Registry())
    assert tagged is True
    assert cast("dict[str, object]", encoded)["$t"] == "ref"


# -- nesting depth ----------------------------------------------------------- #


def nested_lists(levels: int) -> list[object]:
    outer: list[object] = []
    current = outer
    for _ in range(levels - 1):
        child: list[object] = []
        current.append(child)
        current = child
    current.append(1)
    return outer


def nested_dicts(levels: int) -> dict[str, object]:
    outer: dict[str, object] = {}
    current = outer
    for _ in range(levels - 1):
        child: dict[str, object] = {}
        current["k"] = child
        current = child
    current["k"] = 1
    return outer


@pytest.mark.parametrize("build", [nested_lists, nested_dicts])
def test_exactly_max_depth_levels_travel_as_plain_values(build: object) -> None:
    value = build(MAX_DEPTH)  # type: ignore[operator]
    assert encode_value(value, Registry())[1] is False


@pytest.mark.parametrize("build", [nested_lists, nested_dicts])
def test_one_level_more_is_cut_with_a_reference(build: object) -> None:
    value = build(MAX_DEPTH + 1)  # type: ignore[operator]
    assert encode_value(value, Registry())[1] is True


# -- floats ------------------------------------------------------------------ #


def test_finite_floats_are_plain_and_untagged() -> None:
    assert encode_value(1.5, Registry()) == (1.5, False)
    assert encode_value([1.5, -2.25], Registry()) == ([1.5, -2.25], False)
    assert encode_value(1.5, Registry(), strict=True) == (1.5, False)


# -- live output ------------------------------------------------------------- #


def test_finished_lines_are_sent_and_the_rest_waits_for_the_response() -> None:
    php = FakePhp()
    session = Session(bridge=php)

    response = session.handle(
        {"op": "exec", "code": "import sys\nsys.stdout.write('line\\nrest')", "stream": True}
    )

    assert [m["text"] for m in php.sent if m.get("op") == "output"] == ["line\n"]
    assert response["out"] == "rest"


def test_long_output_is_sent_in_chunks_of_exactly_output_chunk_characters() -> None:
    php = FakePhp()
    session = Session(bridge=php)

    session.handle({"op": "exec", "code": f"print('x' * {OUTPUT_CHUNK + 10})", "stream": True})

    sizes = [len(str(m["text"])) for m in php.sent if m.get("op") == "output"]
    assert sizes == [OUTPUT_CHUNK, 11]


# -- callbacks: counters ----------------------------------------------------- #


def php_return(seq: int, value: object) -> Message:
    return {"op": "php_return", "seq": seq, "ok": True, "value": value}


def test_callback_calls_are_numbered_and_the_depth_resets_after_each() -> None:
    calls = 40  # more than the nesting limit, one after another
    php = FakePhp([php_return(seq, seq) for seq in range(1, calls + 1)])
    session = Session(bridge=php)
    session.handle({"op": "exec", "code": f"def run(f):\n    return [f() for _ in range({calls})]"})

    response = session.handle({"op": "call_global", "name": "run", "args": [CALLBACK]})

    assert ok_value(response) == list(range(1, calls + 1))
    assert [m["seq"] for m in php.sent if m.get("op") == "php_call"] == list(range(1, calls + 1))
    assert session.callback_depth == 0


def test_released_callbacks_are_reported_once_and_a_reused_one_is_not_released() -> None:
    session = Session(bridge=FakePhp())
    session.queue_callback_release(8)
    session.queue_callback_release(7)
    kept = session._callback_for(7)  # PHP sent closure 7 again before the release went out

    assert session.handle({"op": "ping"})["release_callbacks"] == [8]
    session.queue_callback_release(9)
    assert session.handle({"op": "ping"})["release_callbacks"] == [9]
    assert "release_callbacks" not in session.handle({"op": "ping"})
    assert kept is session._callback_for(7)


# -- session details ---------------------------------------------------------- #


def test_inline_code_gets_numbered_names_in_tracebacks() -> None:
    session = Session()
    first = error_of(session.handle({"op": "exec", "code": "raise ValueError('one')"}))
    second = error_of(session.handle({"op": "exec", "code": "raise ValueError('two')"}))

    assert "<pyhp-exec-1>" in str(first["traceback"])
    assert "<pyhp-exec-2>" in str(second["traceback"])


def test_ping_reports_the_python_version_and_counts() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "x = object()"})
    session.handle({"op": "get_var", "name": "x"})

    info = cast("dict[str, object]", ok_value(session.handle({"op": "ping"})))
    assert info["python"] == platform.python_version()
    assert info["objects"] == 1
    assert info["callbacks"] == 0


def test_star_imports_respect_all() -> None:
    session = Session()
    session.handle({"op": "import", "module": "*", "from": "json"})

    assert "dumps" in session.globals
    assert "codecs" not in session.globals  # imported by json, but not in json.__all__


def test_the_callable_kind_matches_only_callables() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "number = 1\ndef function(): pass"})

    has = {
        name: session.handle({"op": "has_global", "name": name, "kind": "callable"})["value"]
        for name in ("number", "function")
    }
    assert has == {"number": False, "function": True}


def test_protocol_errors_have_no_traceback() -> None:
    error = error_of(Session().handle({"op": "nope"}))
    assert (error["kind"], error["traceback"]) == ("protocol", "")


def test_a_vectorize_batch_of_one_is_allowed() -> None:
    session = Session(bridge=FakePhp())
    value = {"$t": "vectorize", "fn": CALLBACK, "batch": 1}
    assert session.handle({"op": "set_var", "name": "f", "value": value})["ok"] is True


def test_invalid_base64_is_refused() -> None:
    value = {"$t": "bytes", "v": "aGk=!"}  # valid base64 followed by a stray character
    assert Session().handle({"op": "set_var", "name": "b", "value": value})["ok"] is False


# -- error messages ------------------------------------------------------------ #


@pytest.mark.parametrize(
    ("request_", "message"),
    [
        ({"op": "nope"}, "unknown operation 'nope'"),
        ({"op": "get_var"}, "field 'name' must be a string"),
        ({"op": "get_var", "name": ""}, "field 'name' must not be empty"),
        ({"op": "get_var", "name": "missing_x"}, "name 'missing_x' is not defined"),
        ({"op": "global_names", "kind": "bad"}, "field 'kind' must be one of any, module"),
        ({"op": "call_global", "name": "len", "kwargs": []}, "kwargs must be an object"),
        ({"op": "call_global", "name": "number"}, "'number' is a int, not a function"),
        ({"op": "get_global", "name": "number", "kind": "class"}, "'number' is a int, not a class"),
        ({"op": "set_var", "name": "a"}, "missing field 'value'"),
        ({"op": "import", "module": "json", "as": "1bad"}, "alias must be a Python identifier"),
        ({"op": "import", "module": "*"}, "'*' can only be imported from a module"),
        ({"op": "import", "module": "x", "from": ""}, "field 'from' must be a module name"),
        ({"op": "import", "module": "*", "from": "json", "as": "j"}, "cannot have an alias"),
        ({"op": "ping", "release": "no"}, "release must be a list"),
        ({"op": "ping", "release": ["a"]}, "release id must be an integer"),
        ({"op": "getattr", "ref": 999, "name": "x"}, "Python object #999 no longer exists"),
        (
            {"op": "set_var", "name": "b", "value": {"$t": "bytes", "v": 1}},
            "bytes value must be a base64 string",
        ),
        (
            {"op": "set_var", "name": "f", "value": {"$t": "float", "v": "x"}},
            "invalid special float 'x'",
        ),
        ({"op": "set_var", "name": "t", "value": {"$t": "weird"}}, "unknown value tag 'weird'"),
        (
            {"op": "set_var", "name": "d", "value": {"$t": "dict", "v": 5}},
            "dict pairs must be a list",
        ),
        (
            {
                "op": "set_var",
                "name": "v",
                "value": {"$t": "vectorize", "fn": CALLBACK, "batch": 0},
            },
            "needs a PHP closure and a batch size of at least 1",
        ),
    ],
)
def test_malformed_requests_say_what_is_wrong(request_: Message, message: str) -> None:
    session = Session(bridge=FakePhp())
    session.handle({"op": "exec", "code": "number = 1"})

    assert message in str(error_of(session.handle(request_))["message"])


def test_errors_about_references_name_the_problem() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "import types\nthing = types.SimpleNamespace(x=1)"})
    session.handle({"op": "exec", "code": "plain = object()\nitems = [1]"})
    thing = cast("dict[str, object]", ok_value(session.handle({"op": "get_var", "name": "thing"})))
    plain = cast("dict[str, object]", ok_value(session.handle({"op": "get_var", "name": "plain"})))
    items = cast(
        "dict[str, object]", ok_value(session.handle({"op": "get_var_ref", "name": "items"}))
    )

    def message(request: Message) -> str:
        return str(error_of(session.handle(request))["message"])

    assert message({"op": "call_method", "ref": thing["id"], "name": "x"}) == (
        "attribute 'x' is a int, not a method"
    )
    assert message({"op": "call", "ref": plain["id"]}) == "a object object is not callable"
    assert message({"op": "next_batch", "ref": plain["id"], "size": 1}) == (
        "next_batch needs an iterator reference"
    )
    assert "a pandas DataFrame is needed, not a list" in message(
        {"op": "table", "ref": items["id"]}
    )


def test_decoding_outside_a_session_names_the_problem() -> None:
    with pytest.raises(ConversionError, match="only be used inside a session"):
        decode(CALLBACK, Registry())
    with pytest.raises(ConversionError, match="unexpected wire value of type object"):
        decode(object(), Registry())


# -- frames at their size limit ------------------------------------------------- #


def connected(max_frame: int) -> tuple[Connection, socket.socket]:
    worker_side, php_side = socket.socketpair()
    return Connection(worker_side, max_frame), php_side


def frame_of(sock: socket.socket) -> bytes:
    size = int.from_bytes(sock.recv(4), "big")
    data = b""
    while len(data) < size:
        data += sock.recv(size - len(data))
    return data


def test_a_response_of_exactly_the_limit_is_sent_unchanged() -> None:
    expected = '{"ok": true, "out": "ééééé"}'.encode()  # UTF-8, not \\u escapes
    connection, php = connected(len(expected))
    connection.send({"ok": True, "out": "é" * 5})

    assert frame_of(php) == expected


def test_callback_arguments_of_exactly_the_limit_are_sent() -> None:
    message: Message = {"op": "php_call", "args": ["é"]}
    size = len('{"op": "php_call", "args": ["é"]}'.encode())
    connection, php = connected(size)
    connection.send_call(message)

    assert len(frame_of(php)) == size
    with pytest.raises(ConversionError, match="above the"):
        connected(size - 1)[0].send_call(message)


def test_a_request_of_exactly_the_limit_is_read_and_one_byte_more_is_fatal() -> None:
    body = b'{"op": "ping"}'
    connection, php = connected(len(body))
    php.sendall(len(body).to_bytes(4, "big") + body)
    assert connection.receive() == {"op": "ping"}

    too_small, php2 = connected(len(body) - 1)
    php2.sendall(len(body).to_bytes(4, "big") + body)
    with pytest.raises(FatalFrameError, match="exceeds the limit"):
        too_small.receive()


@pytest.mark.parametrize(
    ("body", "message"),
    [(b"[1]", "not a JSON object"), (b"{nope", "not valid JSON")],
)
def test_unreadable_requests_are_fatal_with_a_reason(body: bytes, message: str) -> None:
    connection, php = connected(1000)
    php.sendall(len(body).to_bytes(4, "big") + body)
    with pytest.raises(FatalFrameError, match=message):
        connection.receive()


def test_a_response_above_the_limit_says_how_to_fix_it() -> None:
    connection, php = connected(1024)  # the smallest max_message_bytes
    connection.send({"ok": True, "value": "x" * 2000, "out": "kept"})

    response = json.loads(frame_of(php))
    assert response["ok"] is False
    assert response["out"] == "kept"
    assert "use a reference" in response["error"]["message"]


# -- authentication ------------------------------------------------------------- #


def auth_frame(token: str, padding: int = 0) -> bytes:
    body = json.dumps({"op": "auth", "token": token, "pad": "x" * padding}).encode()
    return len(body).to_bytes(4, "big") + body


def test_an_auth_frame_of_exactly_the_limit_is_accepted() -> None:
    base = len(auth_frame("secret")) - 4
    worker_side, php = socket.socketpair()
    php.sendall(auth_frame("secret", MAX_AUTH_FRAME - base))
    assert _authenticated(worker_side, "secret", 5) is True


def test_a_bigger_auth_frame_is_refused() -> None:
    base = len(auth_frame("secret")) - 4
    worker_side, php = socket.socketpair()
    php.sendall(auth_frame("secret", MAX_AUTH_FRAME - base + 1))
    assert _authenticated(worker_side, "secret", 5) is False


def test_a_connection_that_closes_before_authenticating_is_refused() -> None:
    worker_side, php = socket.socketpair()
    php.close()
    assert _authenticated(worker_side, "secret", 5) is False


def test_a_connection_that_closes_mid_frame_is_refused() -> None:
    worker_side, php = socket.socketpair()
    php.sendall((100).to_bytes(4, "big") + b'{"op"')
    php.close()
    assert _authenticated(worker_side, "secret", 5) is False


# -- more edges ----------------------------------------------------------------- #


def test_the_session_namespace_is_a_real_module() -> None:
    session = Session(bridge=FakePhp())
    session.handle(
        {
            "op": "exec",
            "code": (
                "import pickle, pyhp, sys\n"
                "def f(): pass\n"
                "pickled = pickle.loads(pickle.dumps(f)) is f\n"
                "registered = sys.modules['__pyhp__'] is sys.modules[__name__]"
            ),
        }
    )
    session.handle({"op": "set_var", "name": "cb", "value": CALLBACK})
    session.handle(
        {"op": "exec", "code": "is_callable = isinstance(cb, pyhp.PhpCallable)\nt = type(cb)"}
    )

    assert session.globals["pickled"] is True
    assert session.globals["registered"] is True
    assert session.globals["is_callable"] is True
    assert ok_value(session.handle({"op": "eval", "expr": "f'{t.__module__}.{t.__name__}'"})) == (
        "pyhp.PhpCallable"
    )


def test_shutdown_answers_null() -> None:
    assert ok_value(Session().handle({"op": "shutdown"})) is None


def test_a_global_set_to_none_is_found() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "nothing = None"})
    assert ok_value(session.handle({"op": "get_var", "name": "nothing"})) is None


def test_objects_with_an_item_method_are_not_unwrapped_like_numpy_scalars() -> None:
    pytest.importorskip("numpy")  # the unwrapping only happens once numpy is loaded

    class Box:
        def item(self) -> int:
            return 5

    encoded = cast("dict[str, object]", encode_value(Box(), Registry())[0])
    assert encoded["$t"] == "ref"


def test_numpy_scalars_without_a_plain_equivalent_keep_their_type() -> None:
    np = pytest.importorskip("numpy")
    encoded = cast("dict[str, object]", encode_value(np.complex128(1 + 2j), Registry())[0])
    assert encoded["type"] == "numpy.complex128"


def test_a_callback_answer_for_another_call_is_an_error() -> None:
    php = FakePhp([php_return(99, "late")])
    session = Session(bridge=php)
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})
    error = error_of(session.handle({"op": "call_global", "name": "run", "args": [CALLBACK]}))
    assert "PHP answered callback call 99 while call 1 was waiting" in str(error["message"])


def test_a_failed_callback_without_details_is_an_error() -> None:
    php = FakePhp([{"op": "php_return", "seq": 1, "ok": False}])
    session = Session(bridge=php)
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})
    error = error_of(session.handle({"op": "call_global", "name": "run", "args": [CALLBACK]}))
    assert error["message"] == "PHP reported a failed callback without details"


def test_table_errors_name_the_column_and_the_problem() -> None:
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

    def table_error(name: str) -> str:
        ref = cast("dict[str, object]", ok_value(session.handle({"op": "get_var", "name": name})))
        return str(error_of(session.handle({"op": "table", "ref": ref["id"]}))["message"])

    assert "column 'price' holds decimal.Decimal values" in table_error("money")
    assert "can't be a distinct PHP array key" in table_error("clash")


def test_a_too_large_response_keeps_its_output_as_utf8() -> None:
    connection, php = connected(1024)
    connection.send({"ok": True, "value": "x" * 2000, "out": "kép"})
    assert "kép".encode() in frame_of(php)
