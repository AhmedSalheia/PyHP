"""PHP callbacks inside the worker, with a scripted stand-in for PHP."""

from __future__ import annotations

import gc
from typing import cast

import pytest

from pyhp_worker import MAX_CALLBACK_DEPTH, PhpCallable, Session, WorkerExit

Message = dict[str, object]
CALLBACK = {"$t": "callback", "id": 7}


class FakePhp:
    """Plays PHP's side: records what the worker sends, answers from a script."""

    def __init__(self, script: list[Message] | None = None) -> None:
        self.script = list(script or [])
        self.sent: list[Message] = []

    def send(self, message: Message) -> None:
        self.sent.append(message)

    def send_call(self, message: Message) -> None:
        self.sent.append(message)

    def receive(self) -> Message | None:
        return self.script.pop(0) if self.script else None


def make(script: list[Message] | None = None) -> tuple[Session, FakePhp]:
    php = FakePhp(script)
    return Session(bridge=php), php


def ok_value(response: Message) -> object:
    assert response["ok"] is True, response
    return response["value"]


def error_of(response: Message) -> dict[str, object]:
    assert response["ok"] is False, response
    return cast("dict[str, object]", response["error"])


def php_return(seq: int, value: object) -> Message:
    return {"op": "php_return", "seq": seq, "ok": True, "value": value}


def call(session: Session, name: str, *args: object) -> Message:
    return session.handle({"op": "call_global", "name": name, "args": list(args), "kwargs": {}})


def test_a_php_callback_is_called_with_positional_and_keyword_arguments() -> None:
    session, php = make([php_return(1, 5)])
    session.handle({"op": "exec", "code": "def apply(f, x):\n    return f(x, scale=2) * 10"})

    assert ok_value(call(session, "apply", CALLBACK, 3)) == 50
    sent = php.sent[0]
    assert sent["op"] == "php_call"
    assert sent["seq"] == 1
    assert sent["cb"] == 7
    assert sent["args"] == [3]
    assert sent["kwargs"] == {"scale": 2}


def test_callback_arguments_that_stay_in_python_are_sent_as_references() -> None:
    session, php = make([php_return(1, None)])
    session.handle({"op": "exec", "code": "def give(f):\n    return f({1, 2})"})

    ok_value(call(session, "give", CALLBACK))
    sent = php.sent[0]
    assert sent["tagged"] is True
    args = sent["args"]
    assert isinstance(args, list)
    assert isinstance(args[0], dict)
    assert args[0]["$t"] == "ref"


def test_a_php_exception_becomes_a_catchable_php_error() -> None:
    error = {"class": "DomainException", "message": "nope", "id": 1}
    session, _ = make([{"op": "php_return", "seq": 1, "ok": False, "error": error}])
    session.handle(
        {
            "op": "exec",
            "code": (
                "from pyhp import PhpError\n"
                "def safe(f):\n"
                "    try:\n"
                "        return f()\n"
                "    except PhpError as e:\n"
                "        return [e.php_class, e.php_message, str(e)]"
            ),
        }
    )

    assert ok_value(call(session, "safe", CALLBACK)) == [
        "DomainException",
        "nope",
        "DomainException: nope",
    ]


def test_an_uncaught_php_error_tells_php_which_exception_to_rethrow() -> None:
    error = {"class": "DomainException", "message": "nope", "id": 1}
    session, _ = make([{"op": "php_return", "seq": 1, "ok": False, "error": error}])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    details = error_of(call(session, "run", CALLBACK))
    assert details["type"] == "pyhp.PhpError"
    assert details["php_error"] == 1


def test_php_can_make_nested_requests_while_python_waits() -> None:
    session, php = make([{"op": "eval", "expr": "40 + 2"}, php_return(1, "done")])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    assert ok_value(call(session, "run", CALLBACK)) == "done"
    nested_response = php.sent[1]
    assert nested_response["value"] == 42


def test_nested_output_is_delivered_with_the_nested_response() -> None:
    nested: Message = {"op": "exec", "code": "print('inner')"}
    session, php = make([nested, php_return(1, None)])
    session.handle(
        {"op": "exec", "code": "def run(f):\n    print('before')\n    f()\n    print('after')"}
    )

    response = call(session, "run", CALLBACK)
    assert php.sent[1]["out"] == "inner\n"
    assert response["out"] == "before\nafter\n"


def test_php_disappearing_during_a_callback_stops_the_worker() -> None:
    session, _ = make([])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    with pytest.raises(WorkerExit):
        call(session, "run", CALLBACK)


def test_a_shutdown_request_during_a_callback_stops_the_worker() -> None:
    session, php = make([{"op": "shutdown"}])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    with pytest.raises(WorkerExit):
        call(session, "run", CALLBACK)
    assert php.sent[1]["ok"] is True


def test_a_reply_for_another_call_is_a_protocol_error() -> None:
    session, _ = make([php_return(99, None)])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    assert error_of(call(session, "run", CALLBACK))["type"] == "RuntimeError"


def test_callbacks_cannot_nest_deeper_than_the_limit() -> None:
    session, _ = make([])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})
    session.callback_depth = MAX_CALLBACK_DEPTH

    details = error_of(call(session, "run", CALLBACK))
    assert details["type"] == "RecursionError"
    assert "32" in str(details["message"])


def test_callbacks_only_work_on_the_thread_serving_php() -> None:
    session, _ = make([])
    session.handle(
        {
            "op": "exec",
            "code": (
                "import threading\n"
                "def in_thread(f):\n"
                "    out = []\n"
                "    def run():\n"
                "        try:\n"
                "            f()\n"
                "        except RuntimeError as e:\n"
                "            out.append(str(e))\n"
                "    t = threading.Thread(target=run)\n"
                "    t.start()\n"
                "    t.join()\n"
                "    return out[0]"
            ),
        }
    )

    assert "thread" in str(ok_value(call(session, "in_thread", CALLBACK)))


def test_callbacks_only_work_while_php_is_waiting_on_a_call() -> None:
    session, _ = make([])
    session.handle({"op": "set_var", "name": "f", "value": CALLBACK})
    callback = session.globals["f"]
    assert callable(callback)

    with pytest.raises(RuntimeError, match="waiting"):
        callback()


def test_callbacks_need_a_php_connection() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    assert "connection" in str(error_of(call(session, "run", CALLBACK))["message"])


def test_the_same_php_closure_is_the_same_python_object() -> None:
    session, _ = make([])
    session.handle({"op": "set_var", "name": "a", "value": CALLBACK})
    session.handle({"op": "set_var", "name": "b", "value": CALLBACK})

    assert session.globals["a"] is session.globals["b"]
    assert isinstance(session.globals["a"], PhpCallable)
    assert repr(session.globals["a"]) == "<PHP callback #7>"


def test_dropped_callbacks_are_released_with_the_next_message() -> None:
    session, _ = make([])
    session.handle({"op": "set_var", "name": "f", "value": CALLBACK})
    response = session.handle({"op": "del_var", "name": "f"})
    gc.collect()
    released = response.get("release_callbacks") or session.handle({"op": "ping"}).get(
        "release_callbacks"
    )

    assert released == [7]
    assert "release_callbacks" not in session.handle({"op": "ping"})


def test_a_pending_release_is_cancelled_when_php_sends_the_callback_again() -> None:
    session, _ = make([])
    session.queue_callback_release(7)
    session.handle({"op": "set_var", "name": "f", "value": CALLBACK})

    assert "release_callbacks" not in session.handle({"op": "ping"})


def test_callbacks_go_back_to_php_as_callbacks() -> None:
    session, _ = make([])
    session.handle({"op": "set_var", "name": "f", "value": CALLBACK})
    response = session.handle({"op": "get_var", "name": "f"})

    assert response["value"] == {"$t": "callback", "id": 7}
    assert response["tagged"] is True


def test_php_returns_release_proxies_php_dropped() -> None:
    session, _ = make([])
    session.handle({"op": "exec", "code": "class O:\n    pass\no = O()"})
    ref = session.handle({"op": "get_var", "name": "o"})["value"]
    assert isinstance(ref, dict)
    ref_id = ref["id"]
    assert isinstance(ref_id, int)
    session.bridge = FakePhp([{**php_return(1, None), "release": [ref_id]}])
    session.handle({"op": "exec", "code": "def run(f):\n    return f()"})

    ok_value(call(session, "run", CALLBACK))
    error = error_of(session.handle({"op": "type", "ref": ref_id}))
    assert error["kind"] == "stale_ref"


def test_ping_counts_live_callbacks() -> None:
    session, _ = make([])
    session.handle({"op": "set_var", "name": "f", "value": CALLBACK})
    value = ok_value(session.handle({"op": "ping"}))
    assert isinstance(value, dict)
    assert value["callbacks"] == 1
    assert value["protocol"] == 2


def test_a_returned_proxy_survives_its_own_release_in_the_same_reply() -> None:
    # PHP drops its proxy right after encoding it as the callback's result, so the
    # release travels in the same reply as the value.
    session, _ = make([])
    session.handle({"op": "exec", "code": "class O:\n    pass\no = O()"})
    ref = session.handle({"op": "get_var_ref", "name": "o"})["value"]
    assert isinstance(ref, dict)
    ref_id = ref["id"]
    assert isinstance(ref_id, int)
    session.bridge = FakePhp([{**php_return(1, {"$t": "ref", "id": ref_id}), "release": [ref_id]}])
    session.handle({"op": "exec", "code": "def run(f):\n    return f() is o"})

    assert ok_value(call(session, "run", CALLBACK)) is True
