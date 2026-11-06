"""Request handling inside the worker, without a socket."""

from __future__ import annotations

from pathlib import Path
from typing import cast

import pytest

from pyhp_worker import Session

Response = dict[str, object]


@pytest.fixture
def session() -> Session:
    return Session()


def ok_value(response: Response) -> object:
    assert response["ok"] is True, response
    return response["value"]


def error_of(response: Response) -> dict[str, object]:
    assert response["ok"] is False, response
    return cast("dict[str, object]", response["error"])


def ref_id(value: object) -> int:
    assert isinstance(value, dict)
    assert value["$t"] == "ref"
    result = value["id"]
    assert isinstance(result, int)
    return result


def test_exec_defines_state_that_survives_between_requests(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "x = 41"}))
    assert ok_value(session.handle({"op": "eval", "expr": "x + 1"})) == 42


def test_print_output_is_captured_per_request(session: Session) -> None:
    response = session.handle({"op": "exec", "code": "print('hi')\nprint('there')"})
    assert response["out"] == "hi\nthere\n"


def test_python_errors_are_reported_with_type_and_traceback(session: Session) -> None:
    error = error_of(
        session.handle({"op": "exec", "code": "def f():\n    raise ValueError('bad')\nf()"})
    )
    assert error["kind"] == "python"
    assert error["type"] == "ValueError"
    assert error["message"] == "bad"
    traceback_text = error["traceback"]
    assert isinstance(traceback_text, str)
    assert "raise ValueError('bad')" in traceback_text
    assert "pyhp_worker.py" not in traceback_text


def test_syntax_errors_are_reported(session: Session) -> None:
    error = error_of(session.handle({"op": "exec", "code": "def ("}))
    assert error["type"] == "SyntaxError"


def test_system_exit_does_not_stop_the_worker(session: Session) -> None:
    error = error_of(session.handle({"op": "exec", "code": "import sys; sys.exit(3)"}))
    assert error["type"] == "SystemExit"
    assert ok_value(session.handle({"op": "eval", "expr": "1"})) == 1


def test_vars_can_be_set_read_listed_and_deleted(session: Session) -> None:
    ok_value(session.handle({"op": "set_var", "name": "limit", "value": 10}))
    assert ok_value(session.handle({"op": "get_var", "name": "limit"})) == 10
    assert ok_value(session.handle({"op": "has_var", "name": "limit"})) is True
    assert ok_value(session.handle({"op": "var_names"})) == ["limit"]
    ok_value(session.handle({"op": "del_var", "name": "limit"}))
    assert ok_value(session.handle({"op": "has_var", "name": "limit"})) is False


def test_reading_a_missing_var_raises_name_error(session: Session) -> None:
    error = error_of(session.handle({"op": "get_var", "name": "missing"}))
    assert error["type"] == "NameError"


def test_global_functions_can_be_called_with_kwargs(session: Session) -> None:
    ok_value(
        session.handle(
            {
                "op": "exec",
                "code": "def greet(n, excited=False):\n    return n + ('!' if excited else '')",
            }
        )
    )
    result = session.handle(
        {"op": "call_global", "name": "greet", "args": ["Ahmed"], "kwargs": {"excited": True}}
    )
    assert ok_value(result) == "Ahmed!"
    assert ok_value(session.handle({"op": "is_callable_global", "name": "greet"})) is True
    assert ok_value(session.handle({"op": "is_callable_global", "name": "nothing"})) is False


def test_calling_a_non_callable_global_is_a_type_error(session: Session) -> None:
    ok_value(session.handle({"op": "set_var", "name": "n", "value": 1}))
    error = error_of(session.handle({"op": "call_global", "name": "n", "args": [], "kwargs": {}}))
    assert error["type"] == "TypeError"


def test_objects_are_returned_as_references_and_can_be_used(session: Session) -> None:
    ok_value(
        session.handle(
            {
                "op": "exec",
                "code": (
                    "class Box:\n"
                    "    def __init__(self):\n"
                    "        self.items = [1]\n"
                    "    def add(self, v):\n"
                    "        self.items.append(v)\n"
                    "        return len(self.items)\n"
                    "box = Box()"
                ),
            }
        )
    )
    box = ref_id(ok_value(session.handle({"op": "get_var", "name": "box"})))
    assert (
        ok_value(
            session.handle(
                {"op": "call_method", "ref": box, "name": "add", "args": [5], "kwargs": {}}
            )
        )
        == 2
    )
    assert ok_value(session.handle({"op": "getattr", "ref": box, "name": "items"})) == [1, 5]
    ok_value(session.handle({"op": "setattr", "ref": box, "name": "label", "value": "L"}))
    assert ok_value(session.handle({"op": "hasattr", "ref": box, "name": "label"})) is True
    ok_value(session.handle({"op": "delattr", "ref": box, "name": "label"}))
    assert ok_value(session.handle({"op": "hasattr", "ref": box, "name": "label"})) is False
    assert ok_value(session.handle({"op": "type", "ref": box})) == "__pyhp__.Box"


def test_items_can_be_read_written_checked_and_deleted(session: Session) -> None:
    ok_value(
        session.handle(
            {"op": "exec", "code": "d = {'a': None, 'b': 2}\nclass W:\n    pass\nw = W()\nw.d = d"}
        )
    )
    w = ref_id(ok_value(session.handle({"op": "get_var", "name": "w"})))
    d = ref_id(ok_value(session.handle({"op": "getattr_ref", "ref": w, "name": "d"})))
    assert ok_value(session.handle({"op": "getitem", "ref": d, "key": "b"})) == 2
    ok_value(session.handle({"op": "setitem", "ref": d, "key": "c", "value": 3}))
    assert ok_value(session.handle({"op": "hasitem", "ref": d, "key": "c"})) is True
    assert ok_value(session.handle({"op": "hasitem", "ref": d, "key": "a"})) is False
    assert ok_value(session.handle({"op": "hasitem", "ref": d, "key": "zzz"})) is False
    ok_value(session.handle({"op": "delitem", "ref": d, "key": "c"}))
    assert ok_value(session.handle({"op": "eval", "expr": "sorted(d)"})) == ["a", "b"]


def test_import_returns_a_module_reference_and_can_bind_an_alias(session: Session) -> None:
    module = ref_id(ok_value(session.handle({"op": "import", "module": "math", "as": "m"})))
    result = session.handle(
        {"op": "call_method", "ref": module, "name": "sqrt", "args": [16], "kwargs": {}}
    )
    assert ok_value(result) == 4.0
    assert ok_value(session.handle({"op": "eval", "expr": "m.pi > 3"})) is True


def test_str_repr_len_and_call(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "items = {1, 2, 3}"}))
    items = ref_id(ok_value(session.handle({"op": "get_var", "name": "items"})))
    assert ok_value(session.handle({"op": "len", "ref": items})) == 3
    assert ok_value(session.handle({"op": "str", "ref": items})) == "{1, 2, 3}"
    assert ok_value(session.handle({"op": "repr", "ref": items})) == "{1, 2, 3}"
    func = ref_id(ok_value(session.handle({"op": "eval", "expr": "sorted"})))
    assert ok_value(
        session.handle(
            {
                "op": "call",
                "ref": func,
                "args": [{"$t": "ref", "id": items}],
                "kwargs": {"reverse": True},
            }
        )
    ) == [3, 2, 1]


def test_to_php_converts_strictly(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "s = {5}\nclass O:\n    pass\nbad = [O()]"}))
    s = ref_id(ok_value(session.handle({"op": "get_var", "name": "s"})))
    assert ok_value(session.handle({"op": "to_php", "ref": s})) == [5]
    bad = ref_id(ok_value(session.handle({"op": "get_var_ref", "name": "bad"})))
    error = error_of(session.handle({"op": "to_php", "ref": bad}))
    assert error["kind"] == "conversion"


def test_iteration_happens_in_batches(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "r = range(5)"}))
    r = ref_id(ok_value(session.handle({"op": "get_var", "name": "r"})))
    iterator = ref_id(ok_value(session.handle({"op": "iter", "ref": r})))
    first = ok_value(session.handle({"op": "next_batch", "ref": iterator, "size": 3}))
    assert first == {"$t": "dict", "v": [["items", [0, 1, 2]], ["done", False]]}
    second = ok_value(session.handle({"op": "next_batch", "ref": iterator, "size": 3}))
    assert second == {"$t": "dict", "v": [["items", [3, 4]], ["done", True]]}


def test_released_references_become_stale(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "class O:\n    pass\no = O()"}))
    o = ref_id(ok_value(session.handle({"op": "get_var", "name": "o"})))
    response = session.handle({"op": "type", "ref": o, "release": [o]})
    error = error_of(response)
    assert error["kind"] == "stale_ref"


def test_get_var_ref_always_returns_a_reference(session: Session) -> None:
    ok_value(session.handle({"op": "set_var", "name": "nums", "value": [1, 2]}))
    nums = ref_id(ok_value(session.handle({"op": "get_var_ref", "name": "nums"})))
    ok_value(
        session.handle(
            {"op": "call_method", "ref": nums, "name": "append", "args": [3], "kwargs": {}}
        )
    )
    assert ok_value(session.handle({"op": "get_var", "name": "nums"})) == [1, 2, 3]


def test_exec_file_runs_in_the_shared_namespace(session: Session, tmp_path: Path) -> None:
    script = tmp_path / "script.py"
    script.write_text("# -*- coding: utf-8 -*-\nfrom_file = __file__\n", encoding="utf-8")
    ok_value(session.handle({"op": "exec_file", "path": str(script)}))
    assert ok_value(session.handle({"op": "get_var", "name": "from_file"})) == str(script)
    assert ok_value(session.handle({"op": "has_var", "name": "__file__"})) is False


def test_exec_file_missing_file_is_a_python_error(session: Session) -> None:
    error = error_of(session.handle({"op": "exec_file", "path": "/no/such/file.py"}))
    assert error["type"] == "FileNotFoundError"


def test_malformed_requests_are_protocol_errors(session: Session) -> None:
    assert error_of(session.handle({"op": "nope"}))["kind"] == "protocol"
    assert error_of(session.handle({"op": "exec"}))["kind"] == "protocol"
    assert (
        error_of(session.handle({"op": "getattr", "ref": "x", "name": "a"}))["kind"] == "protocol"
    )


def test_ping_reports_protocol_version(session: Session) -> None:
    value = ok_value(session.handle({"op": "ping"}))
    assert isinstance(value, dict)
    pairs = dict(cast("list[tuple[str, object]]", value["v"]))
    assert pairs["protocol"] == 1


def test_calling_non_callables_is_a_type_error(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "class O:\n    size = 3\no = O()"}))
    o = ref_id(ok_value(session.handle({"op": "get_var", "name": "o"})))
    call = {"op": "call", "ref": o, "args": [], "kwargs": {}}
    assert error_of(session.handle(call))["type"] == "TypeError"
    method = {"op": "call_method", "ref": o, "name": "size", "args": [], "kwargs": {}}
    assert error_of(session.handle(method))["type"] == "TypeError"


def test_deleting_a_missing_var_raises_name_error(session: Session) -> None:
    assert error_of(session.handle({"op": "del_var", "name": "ghost"}))["type"] == "NameError"


def test_exec_file_restores_a_previous_file_variable(session: Session, tmp_path: Path) -> None:
    script = tmp_path / "s.py"
    script.write_text("seen = __file__\n", encoding="utf-8")
    ok_value(session.handle({"op": "set_var", "name": "__file__", "value": "outer"}))
    ok_value(session.handle({"op": "exec_file", "path": str(script)}))
    assert ok_value(session.handle({"op": "get_var", "name": "__file__"})) == "outer"


def test_import_rejects_invalid_alias(session: Session) -> None:
    response = session.handle({"op": "import", "module": "math", "as": "not valid"})
    assert error_of(response)["kind"] == "protocol"


def test_malformed_call_arguments_are_protocol_errors(session: Session) -> None:
    ok_value(session.handle({"op": "exec", "code": "def f(*a, **k):\n    return 1"}))
    bad_kwargs: dict[str, object] = {"op": "call_global", "name": "f", "args": [], "kwargs": []}
    assert error_of(session.handle(bad_kwargs))["kind"] == "protocol"
    assert error_of(session.handle({"op": "set_var", "name": "x"}))["kind"] == "protocol"
    assert error_of(session.handle({"op": "get_var", "name": ""}))["kind"] == "protocol"
    assert error_of(session.handle({"op": "ping", "release": "x"}))["kind"] == "protocol"


def test_next_batch_requires_an_iterator(session: Session) -> None:
    ok_value(session.handle({"op": "set_var", "name": "l", "value": [1]}))
    lst = ref_id(ok_value(session.handle({"op": "get_var_ref", "name": "l"})))
    response = session.handle({"op": "next_batch", "ref": lst, "size": 2})
    assert error_of(response)["kind"] == "protocol"


def test_deleting_a_missing_var_can_be_allowed(session: Session) -> None:
    ok_value(session.handle({"op": "del_var", "name": "ghost", "missing_ok": True}))


def test_builtins_are_reachable_as_globals(session: Session) -> None:
    result = session.handle({"op": "call_global", "name": "len", "args": [[1, 2]], "kwargs": {}})
    assert ok_value(result) == 2
    assert ok_value(session.handle({"op": "is_callable_global", "name": "sorted"})) is True
    assert ok_value(session.handle({"op": "has_var", "name": "len"})) is False
