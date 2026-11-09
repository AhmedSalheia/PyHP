"""Py::vectorize(): a PHP callback applied to data in chunks, one round trip per chunk."""

from __future__ import annotations

from typing import cast

import pytest

from pyhp_worker import PhpVectorized, Session

Message = dict[str, object]


class FakePhp:
    """Answers every php_call by upper-casing (strings) or doubling (numbers) the chunk."""

    def __init__(self, broken: object = None) -> None:
        self.sent: list[Message] = []
        self.pending: list[Message] = []
        self.broken = broken

    def send(self, message: Message) -> None:
        self.sent.append(message)

    def send_call(self, message: Message) -> None:
        self.sent.append(message)
        if message.get("op") != "php_call":
            return
        chunk = cast("list[object]", cast("list[object]", message["args"])[0])
        answer: object = (
            self.broken
            if self.broken is not None
            else [v.upper() if isinstance(v, str) else cast("int", v) * 2 for v in chunk]
        )
        self.pending.append(
            {"op": "php_return", "seq": message["seq"], "ok": True, "value": answer}
        )

    def receive(self) -> Message | None:
        return self.pending.pop(0) if self.pending else None

    def chunks(self) -> list[list[object]]:
        return [
            cast("list[object]", cast("list[object]", m["args"])[0])
            for m in self.sent
            if m.get("op") == "php_call"
        ]


def run(
    expression: str, batch: int = 2, php: FakePhp | None = None
) -> tuple[Message, FakePhp, Session]:
    php = php or FakePhp()
    session = Session(bridge=php)
    session.handle(
        {
            "op": "set_var",
            "name": "f",
            "value": {"$t": "vectorize", "fn": {"$t": "callback", "id": 4}, "batch": batch},
        }
    )
    return session.handle({"op": "eval", "expr": expression}), php, session


def test_the_marker_becomes_a_python_callable() -> None:
    response, _, session = run("f")

    assert response["ok"] is True
    assert isinstance(session.globals["f"], PhpVectorized)


def test_a_list_is_processed_in_chunks_of_the_batch_size() -> None:
    response, php, _ = run("f([1, 2, 3, 4, 5])")

    assert response["value"] == [2, 4, 6, 8, 10]
    assert php.chunks() == [[1, 2], [3, 4], [5]]


def test_tuples_and_other_iterables_give_lists() -> None:
    assert run("f((1, 2, 3))")[0]["value"] == [2, 4, 6]
    assert run("f(x for x in ['a', 'b'])")[0]["value"] == ["A", "B"]


def test_empty_input_makes_no_php_call() -> None:
    response, php, _ = run("f([])")

    assert response["value"] == []
    assert php.chunks() == []


def test_a_series_keeps_its_index_and_name() -> None:
    pd = pytest.importorskip("pandas")
    php = FakePhp()
    session = Session(bridge=php)
    session.globals["pd"] = pd
    session.handle(
        {
            "op": "set_var",
            "name": "f",
            "value": {"$t": "vectorize", "fn": {"$t": "callback", "id": 4}, "batch": 2},
        }
    )
    session.handle(
        {
            "op": "exec",
            "code": "s = pd.Series(['a', 'b', 'c'], index=[10, 20, 30], name='letters')\n"
            "out = s.pipe(f)",
        }
    )

    shape = session.handle(
        {"op": "eval", "expr": "[type(out).__name__, list(out), list(out.index), out.name]"}
    )
    assert shape["value"] == ["Series", ["A", "B", "C"], [10, 20, 30], "letters"]
    assert php.chunks() == [["a", "b"], ["c"]]


def test_a_numpy_array_gives_an_array() -> None:
    np = pytest.importorskip("numpy")
    php = FakePhp()
    session = Session(bridge=php)
    session.handle(
        {
            "op": "set_var",
            "name": "f",
            "value": {"$t": "vectorize", "fn": {"$t": "callback", "id": 4}, "batch": 1000},
        }
    )
    session.globals["arr"] = np.array([1, 2, 3])
    session.handle({"op": "exec", "code": "out = f(arr)"})

    shape = session.handle({"op": "eval", "expr": "[type(out).__name__, out.tolist()]"})
    assert shape["value"] == ["ndarray", [2, 4, 6]]
    assert php.chunks() == [[1, 2, 3]]


def test_a_callback_returning_the_wrong_number_of_values_is_an_error() -> None:
    response, _, _ = run("f([1, 2, 3])", php=FakePhp(broken=[1]))

    assert response["ok"] is False
    error = cast("dict[str, object]", response["error"])
    assert "returned 1 values for a chunk of 2" in str(error["message"])


def test_a_callback_returning_something_else_than_a_list_is_an_error() -> None:
    response, _, _ = run("f([1])", php=FakePhp(broken="nope"))

    assert response["ok"] is False
    assert "must return an array" in str(cast("dict[str, object]", response["error"])["message"])


def test_invalid_markers_are_rejected() -> None:
    session = Session(bridge=FakePhp())
    bad_batch = {"$t": "vectorize", "fn": {"$t": "callback", "id": 4}, "batch": 0}
    not_a_callback = {"$t": "vectorize", "fn": 5, "batch": 10}

    for value in (bad_batch, not_a_callback):
        response = session.handle({"op": "set_var", "name": "f", "value": value})
        assert response["ok"] is False


def test_repr_says_what_it_is() -> None:
    _, _, session = run("f")

    assert "vectorized PHP callback" in repr(session.globals["f"])
    assert "batch 2" in repr(session.globals["f"])
