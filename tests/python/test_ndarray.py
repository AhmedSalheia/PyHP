"""NumPy arrays as binary frames: Py::toPhp() of an array and Py::ndarray() from PHP.

A numeric array travels as {"$t": "ndarray", "dtype": ..., "shape": [...], "buf": i}
plus its bytes (little-endian, C order) in a binary frame after the JSON message;
the message's "bin" field says how many such frames follow.
"""

from __future__ import annotations

import importlib
import json
import socket
from typing import Any, cast

import pytest

from pyhp_worker import (
    HEADER,
    Connection,
    ConversionError,
    FatalFrameError,
    Registry,
    Session,
    decode,
    encode_for_php,
)

np = pytest.importorskip("numpy")


def tag_and_buffers(value: object) -> tuple[Any, list[bytes]]:
    raw = encode_for_php(value, Registry())
    return raw.value, [bytes(b) for b in raw.buffers]


# -- Python -> PHP ------------------------------------------------------------ #


@pytest.mark.parametrize(
    ("dtype", "wire"),
    [
        ("float64", "f8"),
        ("float32", "f4"),
        ("int64", "i8"),
        ("int32", "i4"),
        ("int16", "i2"),
        ("int8", "i1"),
        ("uint32", "u4"),
        ("uint16", "u2"),
        ("uint8", "u1"),
        ("bool", "b1"),
    ],
)
def test_numeric_arrays_travel_as_their_bytes(dtype: str, wire: str) -> None:
    array = np.array([[1, 0, 1], [0, 1, 1]], dtype=dtype)

    value, buffers = tag_and_buffers(array)

    assert value == {"$t": "ndarray", "dtype": wire, "shape": [2, 3], "buf": 0}
    assert buffers == [array.astype("<" + wire).tobytes()]


def test_the_bytes_are_little_endian_and_in_c_order() -> None:
    array = np.arange(6, dtype=">i4").reshape(2, 3).T  # big-endian, transposed (F order)

    value, buffers = tag_and_buffers(array)

    assert value["shape"] == [3, 2]
    assert np.frombuffer(buffers[0], dtype="<i4").tolist() == [0, 3, 1, 4, 2, 5]


def test_other_float_sizes_become_float32_or_float64() -> None:
    half, _ = tag_and_buffers(np.array([1.5], dtype="float16"))
    long, buffers = tag_and_buffers(np.array([1.5], dtype="longdouble"))

    assert half["dtype"] == "f4"
    assert long["dtype"] == "f8"
    assert np.frombuffer(buffers[0], dtype="<f8").tolist() == [1.5]


def test_uint64_fits_into_int64_or_is_refused() -> None:
    value, buffers = tag_and_buffers(np.array([1, 2**63 - 1], dtype="uint64"))

    with pytest.raises(ConversionError) as too_big:
        encode_for_php(np.array([2**63], dtype="uint64"), Registry())

    assert value["dtype"] == "i8"
    assert np.frombuffer(buffers[0], dtype="<i8").tolist() == [1, 2**63 - 1]
    assert str(too_big.value) == (
        "cannot convert a numpy.ndarray of uint64 values above 9223372036854775807 "
        "(the largest PHP int)"
    )


def test_special_floats_need_no_tags() -> None:
    value, buffers = tag_and_buffers(np.array([np.nan, np.inf, -np.inf]))

    assert value["dtype"] == "f8"
    restored = np.frombuffer(buffers[0], dtype="<f8")
    assert np.isnan(restored[0])
    assert restored[1:].tolist() == [np.inf, -np.inf]


def test_several_arrays_get_one_buffer_each() -> None:
    raw = encode_for_php({"a": np.zeros(2), "b": [np.ones(3, dtype="int8")]}, Registry())

    assert raw.value == {
        "a": {"$t": "ndarray", "dtype": "f8", "shape": [2], "buf": 0},
        "b": [{"$t": "ndarray", "dtype": "i1", "shape": [3], "buf": 1}],
    }
    assert raw.tagged is True
    assert [len(b) for b in raw.buffers] == [16, 3]


def test_empty_arrays_keep_their_shape() -> None:
    value, buffers = tag_and_buffers(np.zeros((3, 0)))

    assert value == {"$t": "ndarray", "dtype": "f8", "shape": [3, 0], "buf": 0}
    assert buffers == [b""]


def test_a_zero_dimensional_array_is_its_single_value() -> None:
    assert tag_and_buffers(np.array(2.5)) == (2.5, [])
    assert tag_and_buffers(np.array(7, dtype="int8")) == (7, [])


def test_arrays_of_other_types_are_copied_value_by_value() -> None:
    assert tag_and_buffers(np.array(["a", "b"])) == (["a", "b"], [])
    assert tag_and_buffers(np.array([[1, "x"]], dtype=object)) == ([[1, "x"]], [])


def test_arrays_whose_values_have_no_php_equivalent_are_refused() -> None:
    with pytest.raises(ConversionError) as error:
        encode_for_php(np.array([1 + 2j]), Registry())

    assert str(error.value) == "cannot convert a complex to a PHP value"


def test_outside_to_php_arrays_stay_in_python() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "import numpy as np\na = np.zeros(3)"})

    response = session.handle({"op": "get_var", "name": "a"})

    assert cast("dict[str, object]", response["value"])["$t"] == "ref"
    assert "_buffers" not in response


def test_to_php_responses_carry_the_buffers() -> None:
    session = Session()
    session.handle({"op": "exec", "code": "import numpy as np\na = np.arange(4, dtype='int32')"})
    ref = cast("dict[str, object]", session.handle({"op": "get_var", "name": "a"})["value"])

    response = session.handle({"op": "to_php", "ref": ref["id"]})

    assert response["value"] == {"$t": "ndarray", "dtype": "i4", "shape": [4], "buf": 0}
    assert response["tagged"] is True
    assert response["_buffers"] == [np.arange(4, dtype="<i4").tobytes()]


# -- PHP -> Python ------------------------------------------------------------ #


def test_ndarray_tags_become_writable_arrays() -> None:
    data = bytearray(np.array([[1.5, 2.5], [3.5, 4.5]]).tobytes())

    array = decode(
        {"$t": "ndarray", "dtype": "f8", "shape": [2, 2], "buf": 0}, Registry(), buffers=[data]
    )

    assert isinstance(array, np.ndarray)
    assert array.dtype == np.float64
    assert array.tolist() == [[1.5, 2.5], [3.5, 4.5]]
    array[0, 0] = 9.0
    assert array[0, 0] == 9.0


@pytest.mark.parametrize(
    ("wire", "dtype"),
    [
        ("f8", "float64"),
        ("f4", "float32"),
        ("i8", "int64"),
        ("i4", "int32"),
        ("i2", "int16"),
        ("i1", "int8"),
        ("u4", "uint32"),
        ("u2", "uint16"),
        ("u1", "uint8"),
        ("b1", "bool"),
    ],
)
def test_every_wire_dtype_decodes(wire: str, dtype: str) -> None:
    expected = np.array([1, 0, 1], dtype=dtype)
    data = bytearray(expected.astype("<" + wire).tobytes())

    array = decode(
        {"$t": "ndarray", "dtype": wire, "shape": [3], "buf": 0}, Registry(), buffers=[data]
    )

    assert array.dtype == np.dtype(dtype)  # type: ignore[attr-defined]
    assert array.tolist() == expected.tolist()  # type: ignore[attr-defined]


def test_arrays_inside_other_values_and_session_requests() -> None:
    session = Session()
    data = bytearray(np.array([1, 2, 3], dtype="<i8").tobytes())
    value = {"$t": "tuple", "v": [{"$t": "ndarray", "dtype": "i8", "shape": [3], "buf": 0}]}

    session.handle({"op": "set_var", "name": "t", "value": value, "_buffers": [data]})

    assert session.handle({"op": "eval", "expr": "int(t[0].sum())"})["value"] == 6
    assert session.handle({"op": "eval", "expr": "type(t[0]).__name__"})["value"] == "ndarray"


@pytest.mark.parametrize(
    ("value", "buffers", "message"),
    [
        (
            {"$t": "ndarray", "dtype": "c16", "shape": [1], "buf": 0},
            [bytearray(16)],
            "unknown array dtype 'c16'",
        ),
        (
            {"$t": "ndarray", "dtype": "f8", "shape": [1], "buf": 1},
            [bytearray(8)],
            "array buffer 1 was not sent",
        ),
        (
            {"$t": "ndarray", "dtype": "f8", "shape": [2], "buf": 0},
            [bytearray(8)],
            "array buffer 0 holds 8 bytes, but shape [2] of f8 needs 16",
        ),
        (
            {"$t": "ndarray", "dtype": "f8", "shape": [-1], "buf": 0},
            [bytearray(8)],
            "array shape must be a list of sizes",
        ),
        (
            {"$t": "ndarray", "dtype": "f8", "shape": "2", "buf": 0},
            [bytearray(8)],
            "array shape must be a list of sizes",
        ),
        (
            {"$t": "ndarray", "dtype": "f8", "shape": [1], "buf": "0"},
            [bytearray(8)],
            "array buffer must be an integer",
        ),
    ],
)
def test_broken_ndarray_tags_are_refused(
    value: dict[str, object], buffers: list[bytearray], message: str
) -> None:
    session = Session()

    response = session.handle({"op": "set_var", "name": "x", "value": value, "_buffers": buffers})

    error = cast("dict[str, object]", response["error"])
    assert response["ok"] is False
    assert str(error["message"]) == message


# -- binary frames ------------------------------------------------------------ #


def read_frame(sock: socket.socket) -> bytes:
    size = HEADER.unpack(sock.recv(HEADER.size, socket.MSG_WAITALL))[0]
    return sock.recv(size, socket.MSG_WAITALL) if size else b""


def test_responses_with_buffers_are_followed_by_binary_frames() -> None:
    worker_side, php = socket.socketpair()
    message: dict[str, object] = {"ok": True, "value": 1, "out": "", "_buffers": [b"abc", b""]}

    Connection(worker_side, 1000).send(message)

    first = json.loads(read_frame(php))
    assert first == {"ok": True, "value": 1, "out": "", "bin": 2}
    assert read_frame(php) == b"abc"
    assert read_frame(php) == b""


def test_a_buffer_above_the_limit_replaces_the_response_with_an_error() -> None:
    worker_side, php = socket.socketpair()

    Connection(worker_side, 100).send({"ok": True, "value": 1, "out": "", "_buffers": [b"x" * 101]})

    response = json.loads(read_frame(php))
    assert response["ok"] is False
    assert response["error"]["kind"] == "too_large"
    assert response["error"]["message"] == (
        "the response is 101 bytes, above the 100-byte limit; "
        "keep the value in Python (use a reference) or raise max_message_bytes"
    )
    assert "bin" not in response


def frame(body: bytes) -> bytes:
    return HEADER.pack(len(body)) + body


def test_requests_with_binary_frames_carry_them_as_buffers() -> None:
    worker_side, php = socket.socketpair()
    php.sendall(frame(b'{"op": "ping", "bin": 2}') + frame(b"\x01\x02") + frame(b""))

    request = Connection(worker_side, 1000).receive()

    assert request == {"op": "ping", "_buffers": [bytearray(b"\x01\x02"), bytearray()]}


@pytest.mark.parametrize(
    ("frames", "message"),
    [
        (
            frame(b'{"op": "ping", "bin": "2"}'),
            "request announces an invalid number of binary frames",
        ),
        (
            frame(b'{"op": "ping", "bin": -1}'),
            "request announces an invalid number of binary frames",
        ),
        (
            frame(b'{"op": "ping", "bin": 1}') + HEADER.pack(31),
            "binary frame of 31 bytes exceeds the limit",
        ),
    ],
)
def test_broken_binary_frames_stop_the_worker(frames: bytes, message: str) -> None:
    worker_side, php = socket.socketpair()
    php.sendall(frames)

    with pytest.raises(FatalFrameError) as error:
        Connection(worker_side, 30).receive()

    assert str(error.value) == message


def test_an_array_from_php_without_numpy_says_what_to_install(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    real_import = importlib.import_module

    def without_numpy(name: str, package: str | None = None) -> object:
        if name == "numpy":
            raise ImportError(name)
        return real_import(name, package)

    monkeypatch.setattr(importlib, "import_module", without_numpy)
    value = {"$t": "ndarray", "dtype": "f8", "shape": [1], "buf": 0}

    with pytest.raises(ConversionError) as error:
        decode(value, Registry(), buffers=[bytearray(8)])

    assert str(error.value) == (
        "Py::ndarray() needs NumPy in the Python environment (pip install numpy)"
    )
