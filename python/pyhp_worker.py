"""PyHP worker.

This script runs in its own Python process, started by the PHP side of PyHP.
It connects back to PHP over a loopback TCP socket, proves its identity with a
one-time token, and then serves requests one at a time until the connection
closes or PHP asks it to shut down.

It deliberately uses only the standard library so it runs in any environment
the user points PyHP at (system Python, venv, conda, ...).

Wire format: every message is a 4-byte big-endian length followed by that many
bytes of UTF-8 JSON. Values that JSON cannot express are "tagged" objects with a
``$t`` key (see ``encode`` and ``decode``). A message with a ``bin`` field is
followed by that many binary frames (same length prefix, raw bytes): the data of
the NumPy arrays in it, referred to by index from their ``ndarray`` tags.
"""

from __future__ import annotations

import base64
import builtins
import contextlib
import hmac
import importlib
import io
import json
import linecache
import math
import numbers
import operator
import os
import re
import socket
import struct
import sys
import threading
import time
import tokenize
import traceback
import types
import weakref
from collections.abc import Callable, Iterable, Iterator
from typing import Any, Final, Protocol, TypeAlias, cast

PROTOCOL_VERSION: Final = 4
# Oldest Python the worker runs on; older ones stop at start-up with a message.
MIN_PYTHON: Final = (3, 11)
# The worker's own folder, kept off sys.path.
_SCRIPT_DIR: Final = os.path.realpath(os.path.dirname(os.path.abspath(__file__)))
# How deep PHP -> Python -> PHP -> ... callbacks may nest.
MAX_CALLBACK_DEPTH: Final = 32
# Most characters sent in one live output frame.
OUTPUT_CHUNK: Final = 16 * 1024
# Module that Python code imports PyHP's own types from: `from pyhp import PhpError`.
PUBLIC_MODULE: Final = "pyhp"
INT64_MIN: Final = -(2**63)
INT64_MAX: Final = 2**63 - 1
MAX_DEPTH: Final = 256
# Encoding uses up to 4 frames per nesting level, plus room for the caller's stack.
RECURSION_LIMIT: Final = MAX_DEPTH * 4 + 1000
DEFAULT_MAX_FRAME: Final = 64 * 1024 * 1024
NAMESPACE_MODULE: Final = "__pyhp__"
HEADER: Final = struct.Struct(">I")
# How long a new connection may take to authenticate before it's dropped.
AUTH_TIMEOUT: Final = 2.0
MAX_AUTH_FRAME: Final = 4096
# Binary frames one message may announce (one per NumPy array in it).
MAX_BINARY_FRAMES: Final = 65536

# PHP converts string keys that look like canonical decimal integers into int keys.
_PHP_INT_KEY: Final = re.compile(r"^(0|-?[1-9][0-9]*)$")

Json: TypeAlias = "bool | int | float | str | list[Json] | dict[str, Json] | None"
Request: TypeAlias = dict[str, object]
Response: TypeAlias = dict[str, object]


class ConversionError(Exception):
    """A value cannot be converted in the requested direction."""


class StaleReferenceError(Exception):
    """A reference id is unknown: released, or from another session."""


class ProtocolError(Exception):
    """A request is malformed. This is a bug in the PHP side, not in user code."""


class WorkerExit(BaseException):  # a control-flow signal, not an error
    """PHP closed the connection or asked the worker to stop.

    A BaseException so that it unwinds through user code and every pending
    request (including nested callbacks) instead of being reported to PHP.
    """


class FatalFrameError(BaseException):
    """A frame from PHP can't be read; the conversation can't continue."""


class PhpError(Exception):
    """A PHP callback threw an exception.

    Python code can catch it: `from pyhp import PhpError`. If it isn't caught,
    PHP rethrows the original PHP exception object.
    """

    def __init__(self, php_class: str, php_message: str, error_id: int) -> None:
        super().__init__(f"{php_class}: {php_message}" if php_message else php_class)
        self.php_class = php_class
        self.php_message = php_message
        self._error_id = error_id  # which PHP exception to rethrow; a protocol detail


PhpError.__module__ = PUBLIC_MODULE


class Registry:
    """Keeps Python objects alive while PHP holds a proxy to them."""

    def __init__(self) -> None:
        self._objects: dict[int, object] = {}
        self._next_id = 1

    def add(self, obj: object) -> int:
        ref_id = self._next_id
        self._next_id += 1
        self._objects[ref_id] = obj
        return ref_id

    def get(self, ref_id: int) -> object:
        try:
            return self._objects[ref_id]
        except KeyError:
            msg = f"Python object #{ref_id} no longer exists in this session"
            raise StaleReferenceError(msg) from None

    def release(self, ids: list[int]) -> None:
        for ref_id in ids:
            self._objects.pop(ref_id, None)

    def __len__(self) -> int:
        return len(self._objects)


def type_name(value: object) -> str:
    cls = type(value)
    if cls.__module__ == "builtins":
        return cls.__qualname__
    return f"{cls.__module__}.{cls.__qualname__}"


_STR_ONLY: Final = frozenset({str})
_MISSING: Final = object()

# --------------------------------------------------------------------------- #
# Python -> wire
# --------------------------------------------------------------------------- #


class _UnconvertibleError(Exception):
    """Internal signal: this value has to stay in Python."""


class _EncodeState:
    """Bookkeeping for one conversion."""

    def __init__(self, registry: Registry, strict: bool) -> None:
        self.registry = registry
        self.strict = strict
        self.path: set[int] = set()
        # Whether any tagged value was produced. If not, PHP can use json_decode's
        # result as-is instead of walking it again (saves time and a full copy).
        self.tagged = False
        # Ids of references created by this conversion (so a caller can undo them).
        self.refs: list[int] = []
        self.first_ref_type = ""
        # Validated key sets: rows of a table repeat the same keys, and checking
        # keys is the most expensive part of converting a dict.
        self.key_sets: dict[tuple[object, ...], tuple[str, ...] | None] = {}
        # Data of NumPy arrays, sent as binary frames after the message (to_php only).
        self.buffers: list[bytes] = []

    def tag(self, value: Json) -> Json:
        self.tagged = True
        return value

    def ref(self, value: object) -> Json:
        encoded = _ref(value, self.registry)
        assert isinstance(encoded, dict)  # noqa: S101  # _ref always builds a dict
        ref_id = encoded["id"]
        assert isinstance(ref_id, int)  # noqa: S101  # ids are ints
        self.refs.append(ref_id)
        if not self.first_ref_type:
            self.first_ref_type = type_name(value)
        return self.tag(encoded)

    def json_keys(self, value: dict[object, object]) -> tuple[str, ...] | None:
        """The JSON object keys for this dict, or None if PHP can't hold its keys.

        Rows of a table share one key set, so the result is cached per key set
        (only for str/int keys, where equal keys always have equal types).
        """
        keys = tuple(value)
        # Only all-str key sets are cached: a str is never equal to a key of another
        # type, so a cache hit can't mix up e.g. 1 and True. (C-level type check.)
        cacheable = set(map(type, keys)) <= _STR_ONLY
        if cacheable:
            cached = self.key_sets.get(keys, _MISSING)
            if cached is not _MISSING:
                return cached  # type: ignore[return-value]  # only key-set results are stored
        seen: set[int | str] = set()
        for key in keys:
            php_key = _php_key(key, self.strict)
            if php_key is None or php_key in seen:
                if self.strict:
                    msg = f"cannot convert dict key {key!r} to a distinct PHP array key"
                    raise ConversionError(msg)
                result = None
                break
            seen.add(php_key)
        else:
            result = tuple(key if type(key) is str else str(key) for key in keys)
        if cacheable:
            self.key_sets[keys] = result
        return result


def encode(value: object, registry: Registry, *, strict: bool = False) -> Json:
    """Convert a Python value for PHP.

    Auto mode (default): exact built-in data types are copied; everything else
    becomes a reference to an object that stays in Python.

    Strict mode (``to_php``): subclasses of built-ins and sets are also copied;
    anything that would need a reference raises ``ConversionError``.

    Dicts become plain JSON objects, which PHP decodes straight into arrays. A
    dict that itself has a "$t" key is wrapped ({"$t": "dict", "o": {...}}) so
    it can't be mistaken for a tagged value.
    """
    return encode_value(value, registry, strict=strict)[0]


def encode_value(value: object, registry: Registry, *, strict: bool = False) -> tuple[Json, bool]:
    """Like ``encode``, also saying whether the result contains any tagged value."""
    _ensure_recursion_room()
    state = _EncodeState(registry, strict)
    result = _encode(value, state, 0)
    return result, state.tagged


def encode_for_php(value: object, registry: Registry) -> _Raw:
    """Strict conversion (``to_php``), with NumPy arrays as binary buffers."""
    _ensure_recursion_room()
    state = _EncodeState(registry, strict=True)
    result = _encode(value, state, 0)
    return _Raw(result, state.tagged, state.buffers)


def _ensure_recursion_room() -> None:
    """Make room for MAX_DEPTH levels of nesting (a few frames each).

    Python 3.11 allows only 1000 frames by default, which a value
    nested close to MAX_DEPTH would exceed.
    """
    if sys.getrecursionlimit() < RECURSION_LIMIT:
        sys.setrecursionlimit(RECURSION_LIMIT)


def _ref(value: object, registry: Registry) -> Json:
    return {"$t": "ref", "id": registry.add(value), "type": type_name(value)}


def _encode(value: object, state: _EncodeState, depth: int) -> Json:  # fast paths
    if not state.strict:
        # Fast paths for the exact types that make up almost all data. Anything
        # unusual falls through to the full rules below, which decide the same way.
        if type(value) is str:
            if value.isascii():
                return value
        elif type(value) is float:
            if value - value == 0.0:  # finite (inf - inf and nan - nan are nan)
                return value
        elif type(value) is int:
            if INT64_MIN <= value <= INT64_MAX:
                return value
        elif value is None or type(value) is bool:
            return value
    try:
        scalar = _encode_scalar(value, state.strict)
    except _UnconvertibleError:
        pass
    else:
        if isinstance(scalar, dict):  # bytes or a special float
            state.tagged = True
        return scalar

    if isinstance(value, PhpCallable):
        return state.tag({"$t": "callback", "id": value._callback_id})

    if state.strict and _is_ndarray(value):
        return _encode_ndarray(cast("Any", value), state, depth)

    if _is_container(value, state.strict):
        if id(value) in state.path or depth >= MAX_DEPTH:
            if state.strict:
                reason = (
                    "a reference cycle" if id(value) in state.path else "nesting deeper than 256"
                )
                msg = f"cannot convert {type_name(value)} containing {reason}"
                raise ConversionError(msg)
            return state.ref(value)
        state.path.add(id(value))
        try:
            converted = _encode_container(value, state, depth)
        finally:
            state.path.discard(id(value))
        if converted is not None:
            return converted

    if state.strict:
        msg = f"cannot convert a {type_name(value)} to a PHP value"
        raise ConversionError(msg)
    return state.ref(value)


# NumPy dtypes on the wire: little-endian, named like NumPy's own codes.
ARRAY_DTYPES: Final = ("b1", "i1", "i2", "i4", "i8", "u1", "u2", "u4", "f4", "f8")


def _is_ndarray(value: object) -> bool:
    numpy = sys.modules.get("numpy")
    ndarray = getattr(numpy, "ndarray", None)
    return isinstance(ndarray, type) and isinstance(value, ndarray)


def _wire_dtype(kind: str, size: int) -> str | None:
    """The wire dtype a NumPy array of this kind and item size is sent as, if any."""
    if kind == "b":
        return "b1"
    if kind in ("i", "u") and size in (1, 2, 4, 8):
        return f"{kind}{size}"
    if kind == "f":
        return "f4" if size <= 4 else "f8"
    return None


def _encode_ndarray(array: Any, state: _EncodeState, depth: int) -> Json:  # noqa: ANN401  # numpy is not imported here
    if array.ndim == 0:
        return _encode(array.item(), state, depth)
    wire = _wire_dtype(array.dtype.kind, array.dtype.itemsize)
    if wire is None:  # strings, objects, dates, complex: value by value
        return _encode(array.tolist(), state, depth)
    if wire == "u8":
        if array.size and int(array.max()) > INT64_MAX:
            msg = (
                f"cannot convert a numpy.ndarray of uint64 values above {INT64_MAX} "
                "(the largest PHP int)"
            )
            raise ConversionError(msg)
        wire = "i8"
    data: bytes = array.astype("<" + wire, copy=False).tobytes(order="C")
    state.buffers.append(data)
    return state.tag(
        {"$t": "ndarray", "dtype": wire, "shape": list(array.shape), "buf": len(state.buffers) - 1}
    )


def _encode_scalar(value: object, strict: bool) -> Json:  # one flat type switch
    if value is None or isinstance(value, bool):
        return value
    if isinstance(value, int) and (strict or type(value) is int):
        if INT64_MIN <= value <= INT64_MAX:
            return int(value)
        raise _UnconvertibleError
    if isinstance(value, float) and (strict or type(value) is float):
        if math.isnan(value):
            return {"$t": "float", "v": "nan"}
        if math.isinf(value):
            return {"$t": "float", "v": "inf" if value > 0 else "-inf"}
        return float(value)
    if isinstance(value, str) and (strict or type(value) is str):
        try:
            value.encode("utf-8")
        except UnicodeEncodeError:
            raise _UnconvertibleError from None
        return str(value)
    if type(value) is bytes:
        return {"$t": "bytes", "v": base64.b64encode(value).decode("ascii")}
    numpy_value = _unwrap_numpy_scalar(value)
    if numpy_value is not value:
        return _encode_scalar(numpy_value, strict)
    if isinstance(value, numbers.Integral) and not isinstance(value, int):
        # Whole numbers that aren't Python ints (sympy.Integer, ...) are exact, so they
        # can become PHP ints losslessly; int subclasses keep their proxy (see above).
        whole = operator.index(value)
        if INT64_MIN <= whole <= INT64_MAX:
            return whole
    raise _UnconvertibleError


def _unwrap_numpy_scalar(value: object) -> object:
    """numpy.int64(5) -> 5, without importing numpy ourselves."""
    numpy = sys.modules.get("numpy")
    if numpy is None:
        return value
    generic = getattr(numpy, "generic", None)
    if not isinstance(generic, type) or not isinstance(value, generic):
        return value
    # datetime64/timedelta64 .item() can return a bare int of nanoseconds: never unwrap them.
    if getattr(getattr(value, "dtype", None), "kind", None) in ("M", "m"):
        return value
    item = getattr(value, "item", None)
    if item is None:
        return value
    result: object = item()
    if isinstance(result, (bool, int, float, str, bytes)) or result is None:
        return result
    return value


def _is_container(value: object, strict: bool) -> bool:
    if strict:
        return isinstance(value, (list, tuple, dict, set, frozenset))
    return type(value) in (list, tuple, dict)


def _encode_container(value: object, state: _EncodeState, depth: int) -> Json:
    """Encode a list/tuple/set/dict, or return None if PHP can't hold this dict."""
    if isinstance(value, dict):
        # Check every key before encoding any value, so a dict that has to stay in
        # Python doesn't leave references to its values behind in the registry.
        json_keys = state.json_keys(value)
        if json_keys is None:
            return None
        values = _encode_items(value.values(), state, depth + 1)
        obj: dict[str, Json] = dict(zip(json_keys, values, strict=True))
        return state.tag({"$t": "dict", "o": obj}) if "$t" in obj else obj
    if isinstance(value, (list, tuple, set, frozenset)):
        return _encode_items(value, state, depth + 1)
    return None


def _encode_items(items: Iterable[object], state: _EncodeState, depth: int) -> list[Json]:
    """Encode a sequence; plain scalars skip the function call (most of a table)."""
    if state.strict:
        return [_encode(item, state, depth) for item in items]
    out: list[Json] = []
    append = out.append
    for item in items:
        if (
            (type(item) is float and item - item == 0.0)
            or (type(item) is int and INT64_MIN <= item <= INT64_MAX)
            or (type(item) is str and item.isascii())
            or item is None
            or type(item) is bool
        ):
            append(item)
        else:
            append(_encode(item, state, depth))
    return out


def _php_key(key: object, strict: bool) -> int | str | None:
    """The key PHP would actually use, or None if PHP cannot represent it."""
    if isinstance(key, bool):
        return None
    if isinstance(key, int) and (strict or type(key) is int):
        return int(key) if INT64_MIN <= key <= INT64_MAX else None
    if isinstance(key, str) and (strict or type(key) is str):
        if _PHP_INT_KEY.match(key) and INT64_MIN <= int(key) <= INT64_MAX:
            return int(key)
        return str(key)
    return None


# --------------------------------------------------------------------------- #
# wire -> Python
# --------------------------------------------------------------------------- #


CallbackFactory: TypeAlias = Callable[[int], object]


def decode(  # one flat tag switch
    data: object,
    registry: Registry,
    callbacks: CallbackFactory | None = None,
    buffers: list[bytearray] | None = None,
) -> object:
    if data is None or isinstance(data, (bool, int, float, str)):
        return data
    if isinstance(data, list):
        return [decode(item, registry, callbacks, buffers) for item in data]
    if not isinstance(data, dict):
        msg = f"unexpected wire value of type {type_name(data)}"
        raise ConversionError(msg)
    tag = data.get("$t")
    raw = data.get("v")
    if tag == "ref":
        return registry.get(_require_int(data.get("id"), "ref id"))
    if tag == "callback":
        if callbacks is None:
            msg = "PHP callbacks can only be used inside a session"
            raise ConversionError(msg)
        return callbacks(_require_int(data.get("id"), "callback id"))
    if tag == "vectorize":
        function = decode(data.get("fn"), registry, callbacks, buffers)
        batch = _require_int(data.get("batch"), "batch size")
        if not isinstance(function, PhpCallable) or batch < 1:
            msg = "Py::vectorize() needs a PHP closure and a batch size of at least 1"
            raise ConversionError(msg)
        return PhpVectorized(function, batch)
    if tag == "dict":
        result: dict[object, object] = {}
        for pair in _require_list(raw, "dict pairs"):
            key, item = _require_list(pair, "dict pair")
            result[decode(key, registry, callbacks, buffers)] = decode(
                item, registry, callbacks, buffers
            )
        return result
    if tag == "tuple":
        return tuple(
            decode(item, registry, callbacks, buffers) for item in _require_list(raw, "tuple")
        )
    if tag == "slice":
        start, stop, step = (
            decode(item, registry, callbacks, buffers) for item in _require_list(raw, "slice")
        )
        return slice(start, stop, step)
    if tag == "bytes":
        if not isinstance(raw, str):
            msg = "bytes value must be a base64 string"
            raise ConversionError(msg)
        return base64.b64decode(raw, validate=True)
    if tag == "float":
        if raw in ("nan", "inf", "-inf"):
            return float(str(raw))
        msg = f"invalid special float {raw!r}"
        raise ConversionError(msg)
    if tag == "ndarray":
        return _decode_ndarray(data, buffers or [])
    msg = f"unknown value tag {tag!r}"
    raise ConversionError(msg)


def _decode_ndarray(data: dict[str, object], buffers: list[bytearray]) -> object:
    dtype = data.get("dtype")
    if dtype not in ARRAY_DTYPES:
        msg = f"unknown array dtype {dtype!r}"
        raise ConversionError(msg)
    shape = data.get("shape")
    if not isinstance(shape, list) or not all(type(size) is int and size >= 0 for size in shape):
        msg = "array shape must be a list of sizes"
        raise ConversionError(msg)
    index = data.get("buf")
    if type(index) is not int:
        msg = "array buffer must be an integer"
        raise ConversionError(msg)
    if not 0 <= index < len(buffers):
        msg = f"array buffer {index} was not sent"
        raise ConversionError(msg)
    buffer = buffers[index]
    needed = math.prod(shape) * int(dtype[1])
    if len(buffer) != needed:
        msg = (
            f"array buffer {index} holds {len(buffer)} bytes, "
            f"but shape {shape} of {dtype} needs {needed}"
        )
        raise ConversionError(msg)
    try:
        numpy = importlib.import_module("numpy")
    except ImportError:
        msg = "Py::ndarray() needs NumPy in the Python environment (pip install numpy)"
        raise ConversionError(msg) from None
    # A bytearray makes a writable array without copying the data.
    return numpy.frombuffer(buffer, dtype="<" + dtype).reshape(shape)


def _buffers_of(message: Request) -> list[bytearray]:
    """The binary frames that came with a message (see Connection.receive)."""
    return cast("list[bytearray]", message.get("_buffers", []))


def _require_int(value: object, what: str) -> int:
    if isinstance(value, int) and not isinstance(value, bool):
        return value
    msg = f"{what} must be an integer"
    raise ProtocolError(msg)


def _require_list(value: object, what: str) -> list[object]:
    if isinstance(value, list):
        return value
    msg = f"{what} must be a list"
    raise ProtocolError(msg)


# --------------------------------------------------------------------------- #
# Session: the shared namespace and the request handlers
# --------------------------------------------------------------------------- #

Handler: TypeAlias = Callable[[Request], object]


class _Raw:
    """Marks a handler result that is already wire-encoded."""

    def __init__(self, value: Json, tagged: bool, buffers: list[bytes] | None = None) -> None:
        self.value = value
        self.tagged = tagged
        self.buffers = buffers or []


class _Subscriptable(Protocol):
    def __getitem__(self, key: object, /) -> object: ...


class _Sized(Protocol):
    def __len__(self) -> int: ...


class _Iterable(Protocol):
    def __iter__(self) -> Iterator[object]: ...


class Bridge(Protocol):
    """The connection to PHP, as the session sees it."""

    def send(self, message: Response) -> None: ...

    def send_call(self, message: dict[str, object]) -> None: ...

    def receive(self) -> Request | None: ...


class _LiveOutput(io.StringIO):
    """stdout during a streamed call: sends finished lines to PHP while the call runs.

    Only the thread that serves PHP writes to the socket; text printed by other
    threads waits here until that thread sends next, or until the response.
    """

    def __init__(self, bridge: Bridge, thread: int) -> None:
        super().__init__()
        self._bridge = bridge
        self._thread = thread
        self._pending: list[str] = []
        self._lock = threading.Lock()

    def write(self, text: str) -> int:
        with self._lock:
            self._pending.append(text)
        if "\n" in text and threading.get_ident() == self._thread:
            self._send(whole_lines=True)
        return len(text)

    def flush(self) -> None:
        if threading.get_ident() == self._thread:
            self._send(whole_lines=False)

    def getvalue(self) -> str:
        """What hasn't been sent yet; it travels with the response."""
        with self._lock:
            return "".join(self._pending)

    def _send(self, *, whole_lines: bool) -> None:
        with self._lock:
            text = "".join(self._pending)
            cut = text.rfind("\n") + 1 if whole_lines else len(text)
            ready, rest = text[:cut], text[cut:]
            self._pending = [rest] if rest else []
        for start in range(0, len(ready), OUTPUT_CHUNK):
            self._bridge.send_call({"op": "output", "text": ready[start : start + OUTPUT_CHUNK]})


class PhpVectorized:
    """Py::vectorize(): a PHP closure applied to data in chunks, one round trip per chunk.

    Called with a pandas Series, it returns a Series with the same index and name;
    with a NumPy array, an array; with anything else iterable, a list. The PHP
    closure receives each chunk as an array and returns an array of the same size.
    """

    __slots__ = ("batch_size", "function")

    def __init__(self, function: PhpCallable, batch_size: int) -> None:
        self.function = function
        self.batch_size = batch_size

    def __call__(self, data: Iterable[object]) -> object:
        values = list(data)
        results: list[object] = []
        for start in range(0, len(values), self.batch_size):
            chunk = values[start : start + self.batch_size]
            answer = self.function(chunk)
            if not isinstance(answer, list):
                msg = (
                    "a vectorized PHP callback must return an array with one value per input "
                    f"value (a list: use array_values() after filtering), got {type_name(answer)}"
                )
                raise TypeError(msg)
            if len(answer) != len(chunk):
                msg = (
                    f"the vectorized PHP callback returned {len(answer)} values "
                    f"for a chunk of {len(chunk)}"
                )
                raise ValueError(msg)
            results.extend(answer)
        return _shaped_like(data, results)

    def __repr__(self) -> str:
        return f"<vectorized PHP callback {self.function._callback_id}, batch {self.batch_size}>"


def _shaped_like(data: object, results: list[object]) -> object:
    """The results as the same kind of container as the input, where that matters."""
    pandas = sys.modules.get("pandas")
    series = getattr(pandas, "Series", None)
    if isinstance(series, type) and isinstance(data, series):
        return series(results, index=getattr(data, "index", None), name=getattr(data, "name", None))
    numpy = sys.modules.get("numpy")
    ndarray = getattr(numpy, "ndarray", None)
    if isinstance(ndarray, type) and isinstance(data, ndarray):
        return getattr(numpy, "array")(results)  # noqa: B009  # numpy is only imported by user code
    return results


class PhpCallable:
    """A PHP closure that Python code can call like a function."""

    __slots__ = ("__weakref__", "_callback_id", "_session")

    def __init__(self, session: Session, callback_id: int) -> None:
        self._session = session
        self._callback_id = callback_id

    def __call__(self, *args: object, **kwargs: object) -> object:
        return self._session.call_php(self._callback_id, args, kwargs)

    def __repr__(self) -> str:
        return f"<PHP callback #{self._callback_id}>"


PhpCallable.__module__ = PUBLIC_MODULE


class Session:
    def __init__(self, bridge: Bridge | None = None) -> None:
        self.module = types.ModuleType(NAMESPACE_MODULE)
        # Registering the module lets pickle find functions/classes defined in the session.
        sys.modules[NAMESPACE_MODULE] = self.module
        public = types.ModuleType(PUBLIC_MODULE)
        public.PhpError = PhpError  # type: ignore[attr-defined]  # building a module
        public.PhpCallable = PhpCallable  # type: ignore[attr-defined]  # building a module
        sys.modules[PUBLIC_MODULE] = public
        self.globals: dict[str, object] = self.module.__dict__
        self.globals["__builtins__"] = builtins
        self.registry = Registry()
        self._snippets = 0
        # PHP callbacks
        self.bridge = bridge
        self.callback_depth = 0
        self._callables: weakref.WeakValueDictionary[int, PhpCallable] = (
            weakref.WeakValueDictionary()
        )
        self._released_callbacks: list[int] = []
        self._call_seq = 0
        self._active_requests = 0
        self._thread = threading.get_ident()
        self._handlers: dict[str, Handler] = {
            "ping": self._ping,
            "shutdown": lambda _: None,
            "exec": self._exec,
            "exec_file": self._exec_file,
            "eval": self._eval,
            "get_var": lambda r: self.globals_get(_name(r, "name")),
            "get_var_ref": lambda r: self._as_ref(self.globals_get(_name(r, "name"))),
            "set_var": self._set_var,
            "del_var": self._del_var,
            "has_var": lambda r: _name(r, "name") in self.globals,
            "var_names": lambda _: [n for n in self.globals if not n.startswith("__")],
            "is_callable_global": self._is_callable_global,
            "global_names": self._global_names,
            "get_global": lambda r: self._as_ref(self._global_of_kind(r)),
            "has_global": self._has_global,
            "call_global": self._call_global,
            "import": self._import,
            "getattr": lambda r: getattr(self._target(r), _name(r, "name")),
            "getattr_ref": lambda r: self._as_ref(getattr(self._target(r), _name(r, "name"))),
            "setattr": self._setattr,
            "delattr": lambda r: delattr(self._target(r), _name(r, "name")),
            "hasattr": lambda r: hasattr(self._target(r), _name(r, "name")),
            "getitem": lambda r: self._subscriptable(r)[self._arg(r, "key")],
            "setitem": self._setitem,
            "delitem": self._delitem,
            "hasitem": self._hasitem,
            "call": self._call,
            "call_method": self._call_method,
            "str": lambda r: str(self._target(r)),
            "repr": lambda r: repr(self._target(r)),
            "len": lambda r: len(self._sized(r)),
            "type": lambda r: type_name(self._target(r)),
            "to_php": lambda r: encode_for_php(self._target(r), self.registry),
            "iter": lambda r: self._as_ref(iter(self._iterable(r))),
            "next_batch": self._next_batch,
            "table": self._table,
        }

    # -- request dispatch ---------------------------------------------------- #

    def handle(self, request: Request) -> Response:
        return self._with_releases(self._handle(request))

    def _handle(self, request: Request) -> Response:
        bridge = self.bridge
        output = (
            _LiveOutput(bridge, self._thread)
            if request.get("stream") is True and bridge is not None
            else io.StringIO()
        )
        try:
            release = request.get("release", [])
            self.registry.release(
                [_require_int(i, "release id") for i in _require_list(release, "release")]
            )
            op = request.get("op")
            handler = self._handlers.get(op) if isinstance(op, str) else None
            if handler is None:
                msg = f"unknown operation {op!r}"
                raise ProtocolError(msg)
            self._active_requests += 1
            try:
                with contextlib.redirect_stdout(output):
                    result = handler(request)
            finally:
                self._active_requests -= 1
            buffers: list[bytes] = []
            if isinstance(result, _Raw):
                value, tagged, buffers = result.value, result.tagged, result.buffers
            else:
                value, tagged = encode_value(result, self.registry)
        except (WorkerExit, FatalFrameError):
            raise
        except ProtocolError as error:
            return _error_response("protocol", error, output)
        except StaleReferenceError as error:
            return _error_response("stale_ref", error, output)
        except ConversionError as error:
            return _error_response("conversion", error, output)
        except BaseException as error:  # forwarded to PHP as PythonException
            return _error_response("python", error, output, with_traceback=True)
        response: Response = {
            "ok": True,
            "value": value,
            "tagged": tagged,
            "out": output.getvalue(),
        }
        if buffers:
            response["_buffers"] = buffers  # sent as binary frames by Connection.send
        return response

    # -- PHP callbacks ------------------------------------------------------- #

    def call_php(
        self, callback_id: int, args: tuple[object, ...], kwargs: dict[str, object]
    ) -> object:
        """Call PHP closure `callback_id` and wait for its result.

        While waiting, PHP may send nested requests (the closure calling Python
        again); they are served here, on the same thread, until PHP answers.
        """
        if threading.get_ident() != self._thread:
            msg = (
                "PHP callbacks can only be called from the thread that serves PHP's "
                "requests, not from other threads"
            )
            raise RuntimeError(msg)
        if self._active_requests == 0:
            msg = "PHP callbacks can only be called while PHP is waiting on a call into Python"
            raise RuntimeError(msg)
        bridge = self.bridge
        if bridge is None:
            msg = "this session has no PHP connection to call back into"
            raise RuntimeError(msg)
        if self.callback_depth >= MAX_CALLBACK_DEPTH:
            msg = f"PHP callbacks are nested more than {MAX_CALLBACK_DEPTH} levels deep"
            raise RecursionError(msg)

        self._call_seq += 1
        seq = self._call_seq
        encoded_args, args_tagged = encode_value(list(args), self.registry)
        encoded_kwargs, kwargs_tagged = encode_value(kwargs, self.registry)
        message: dict[str, object] = {
            "op": "php_call",
            "seq": seq,
            "cb": callback_id,
            "args": encoded_args,
            "kwargs": encoded_kwargs,
            "tagged": args_tagged or kwargs_tagged,
        }
        bridge.send_call(self._with_releases(message))
        self.callback_depth += 1
        try:
            while True:
                frame = bridge.receive()
                if frame is None:
                    raise WorkerExit
                if frame.get("op") == "php_return":
                    return self._php_result(frame, seq)
                bridge.send(self.handle(frame))  # a nested request
                if frame.get("op") == "shutdown":
                    raise WorkerExit
        finally:
            self.callback_depth -= 1

    def _php_result(self, frame: Request, seq: int) -> object:
        if frame.get("seq") != seq:
            msg = f"PHP answered callback call {frame.get('seq')!r} while call {seq} was waiting"
            raise RuntimeError(msg)
        # Decode the result before applying releases: PHP drops its proxy right
        # after encoding it, so a returned proxy is often released in this frame.
        value = (
            decode(frame.get("value"), self.registry, self._callback_for, _buffers_of(frame))
            if frame.get("ok") is True
            else None
        )
        release = frame.get("release", [])
        self.registry.release(
            [_require_int(i, "release id") for i in _require_list(release, "release")]
        )
        if frame.get("ok") is True:
            return value
        error = frame.get("error")
        if not isinstance(error, dict):
            msg = "PHP reported a failed callback without details"
            raise ProtocolError(msg)
        return_id = error.get("id")
        raise PhpError(
            str(error.get("class", "Exception")),
            str(error.get("message", "")),
            return_id if isinstance(return_id, int) else seq,
        )

    def _callback_for(self, callback_id: int) -> PhpCallable:
        """One PhpCallable per PHP closure id, released (to PHP) when Python drops it."""
        existing = self._callables.get(callback_id)
        if existing is not None:
            return existing
        # PHP is sending this id again, so a release queued for it is stale.
        self._released_callbacks = [i for i in self._released_callbacks if i != callback_id]
        callback = PhpCallable(self, callback_id)
        self._callables[callback_id] = callback
        weakref.finalize(callback, self.queue_callback_release, callback_id)
        return callback

    def queue_callback_release(self, callback_id: int) -> None:
        self._released_callbacks.append(callback_id)

    def _with_releases(self, message: dict[str, object]) -> dict[str, object]:
        """Tell PHP which closures Python no longer needs, with the next message."""
        if self._released_callbacks:
            message["release_callbacks"] = self._released_callbacks
            self._released_callbacks = []
        return message

    # -- helpers -------------------------------------------------------------- #

    def globals_get(self, name: str) -> object:
        """Python's own name lookup: session globals first, then builtins."""
        if name in self.globals:
            return self.globals[name]
        if hasattr(builtins, name):
            return getattr(builtins, name)
        msg = f"name '{name}' is not defined"
        raise NameError(msg)

    def _as_ref(self, value: object) -> _Raw:
        return _Raw(_ref(value, self.registry), tagged=True)

    def _target(self, request: Request) -> object:
        return self.registry.get(_require_int(request.get("ref"), "ref"))

    def _subscriptable(self, request: Request) -> _Subscriptable:
        # Python raises TypeError itself if not subscriptable
        return cast("_Subscriptable", self._target(request))

    def _sized(self, request: Request) -> _Sized:
        # Python raises TypeError itself if unsized
        return cast("_Sized", self._target(request))

    def _iterable(self, request: Request) -> _Iterable:
        # Python raises TypeError itself if not iterable
        return cast("_Iterable", self._target(request))

    def _arg(self, request: Request, key: str) -> object:
        if key not in request:
            msg = f"missing field {key!r}"
            raise ProtocolError(msg)
        return decode(request[key], self.registry, self._callback_for, _buffers_of(request))

    def _call_args(self, request: Request) -> tuple[list[object], dict[str, object]]:
        buffers = _buffers_of(request)
        args = decode(
            _require_list(request.get("args", []), "args"),
            self.registry,
            self._callback_for,
            buffers,
        )
        raw_kwargs = request.get("kwargs", {})
        if not isinstance(raw_kwargs, dict):
            msg = "kwargs must be an object"
            raise ProtocolError(msg)
        kwargs = {
            str(k): decode(v, self.registry, self._callback_for, buffers)
            for k, v in raw_kwargs.items()
        }
        assert isinstance(args, list)  # noqa: S101  # decode of a list is a list
        return args, kwargs

    def _compile(self, source: str, mode: str, filename: str | None = None) -> types.CodeType:
        if filename is None:
            self._snippets += 1
            filename = f"<pyhp-{mode}-{self._snippets}>"
            # Lets tracebacks show the offending source line of inline code.
            lines = source.splitlines(keepends=True)
            linecache.cache[filename] = (len(source), None, lines, filename)
        return compile(source, filename, mode)

    # -- handlers ------------------------------------------------------------- #

    def _ping(self, _: Request) -> object:
        return {
            "protocol": PROTOCOL_VERSION,
            "python": sys.version.split()[0],
            "executable": sys.executable,
            "objects": len(self.registry),
            "callbacks": len(self._callables),
        }

    def _exec(self, request: Request) -> object:
        exec(self._compile(_text(request, "code"), "exec"), self.globals)
        return None

    def _exec_file(self, request: Request) -> object:
        path = _text(request, "path")
        with tokenize.open(path) as handle:  # honours "# -*- coding: ... -*-" cookies
            source = handle.read()
        code = self._compile(source, "exec", filename=path)
        missing = object()
        previous = self.globals.get("__file__", missing)
        self.globals["__file__"] = path
        try:
            exec(code, self.globals)
        finally:
            if previous is missing:
                self.globals.pop("__file__", None)
            else:
                self.globals["__file__"] = previous
        return None

    def _eval(self, request: Request) -> object:
        return eval(self._compile(_text(request, "expr"), "eval"), self.globals)

    def _set_var(self, request: Request) -> object:
        self.globals[_name(request, "name")] = self._arg(request, "value")
        return None

    def _del_var(self, request: Request) -> object:
        name = _name(request, "name")
        if request.get("missing_ok") is True:
            self.globals.pop(name, None)
            return None
        self.globals_get(name)
        del self.globals[name]
        return None

    def _is_callable_global(self, request: Request) -> object:
        try:
            return callable(self.globals_get(_name(request, "name")))
        except NameError:
            return False

    def _call_global(self, request: Request) -> object:
        name = _name(request, "name")
        function = self._global_of_kind(request) if "kind" in request else self.globals_get(name)
        if not callable(function):
            msg = f"'{name}' is a {type_name(function)}, not a function"
            raise TypeError(msg)
        args, kwargs = self._call_args(request)
        return function(*args, **kwargs)

    def _global_of_kind(self, request: Request) -> object:
        """The global `name`, which must be of `kind`; builtins count, as in Python."""
        name = _name(request, "name")
        kind = _kind(request)
        value = self.globals_get(name)
        if not _is_kind(value, kind):
            msg = f"'{name}' is a {type_name(value)}, not a {kind}"
            raise TypeError(msg)
        return value

    def _has_global(self, request: Request) -> object:
        kind = _kind(request)
        try:
            value = self.globals_get(_name(request, "name"))
        except NameError:
            return False
        return _is_kind(value, kind)

    def _global_names(self, request: Request) -> object:
        kind = _kind(request)
        return [
            name
            for name, value in self.globals.items()
            if not name.startswith("__") and _is_kind(value, kind)
        ]

    def _import(self, request: Request) -> object:
        """`import m`, `import m as a`, `from p import n [as a]`, `from p import *`."""
        name = _text(request, "module")
        package = request.get("from")
        alias = request.get("as")
        if alias is not None and (not isinstance(alias, str) or not alias.isidentifier()):
            msg = "import alias must be a Python identifier"
            raise ProtocolError(msg)
        if package is None:
            if name == "*":
                msg = "'*' can only be imported from a module (use from=...)"
                raise ProtocolError(msg)
            module = importlib.import_module(name)
            if alias is not None:
                self.globals[alias] = module
            else:
                # Like Python: `import os.path` binds `os`.
                top = name.partition(".")[0]
                self.globals[top] = sys.modules[top]
            return self._as_ref(module)
        if not isinstance(package, str) or package == "":
            msg = "field 'from' must be a module name"
            raise ProtocolError(msg)
        source = importlib.import_module(package)
        if name == "*":
            if alias is not None:
                msg = "'from ... import *' cannot have an alias"
                raise ProtocolError(msg)
            self._import_star(source)
            return None
        value = self._from_import(source, package, name)
        self.globals[alias if alias is not None else name] = value
        return value

    def _from_import(self, source: types.ModuleType, package: str, name: str) -> object:
        try:
            return getattr(source, name)
        except AttributeError:
            pass
        try:  # a submodule that hasn't been imported yet
            return importlib.import_module(f"{package}.{name}")
        except ModuleNotFoundError:
            msg = f"cannot import name '{name}' from '{package}'"
            raise ImportError(msg, name=package) from None

    def _import_star(self, source: types.ModuleType) -> None:
        public = getattr(source, "__all__", None)
        if public is None:
            public = [n for n in vars(source) if not n.startswith("_")]
        for name in public:
            self.globals[name] = getattr(source, name)

    def _setattr(self, request: Request) -> object:
        setattr(self._target(request), _name(request, "name"), self._arg(request, "value"))
        return None

    def _setitem(self, request: Request) -> object:
        target = self._target(request)
        target[self._arg(request, "key")] = self._arg(request, "value")  # type: ignore[index]  # Python raises TypeError itself
        return None

    def _delitem(self, request: Request) -> object:
        target = self._target(request)
        del target[self._arg(request, "key")]  # type: ignore[attr-defined]  # Python raises TypeError itself
        return None

    def _hasitem(self, request: Request) -> object:
        """PHP isset() semantics: the key exists and its value is not None."""
        try:
            value = self._subscriptable(request)[self._arg(request, "key")]
        except (KeyError, IndexError):
            return False
        return value is not None

    def _call(self, request: Request) -> object:
        target = self._target(request)
        if not callable(target):
            msg = f"a {type_name(target)} object is not callable"
            raise TypeError(msg)
        args, kwargs = self._call_args(request)
        return target(*args, **kwargs)

    def _call_method(self, request: Request) -> object:
        method = getattr(self._target(request), _name(request, "name"))
        if not callable(method):
            msg = f"attribute '{_name(request, 'name')}' is a {type_name(method)}, not a method"
            raise TypeError(msg)
        args, kwargs = self._call_args(request)
        return method(*args, **kwargs)

    def _table(self, request: Request) -> object:
        """A pandas DataFrame as {"columns": [names], "data": [[column values], ...]}.

        Made for PHP: missing values (NaN, None, NaT, pd.NA) become null, dates and
        durations become ISO 8601 strings, and the index is left out. Cells PHP
        can't hold (Decimal, date objects, ...) are an error naming the column,
        never a reference per cell.
        """
        frame = self._target(request)
        pandas = sys.modules.get("pandas")
        data_frame = getattr(pandas, "DataFrame", None) if pandas is not None else None
        if not isinstance(data_frame, type) or not isinstance(frame, data_frame):
            msg = f"a pandas DataFrame is needed, not a {type_name(frame)}"
            raise TypeError(msg)
        names: list[object] = frame.columns.tolist()  # type: ignore[attr-defined]  # checked to be a DataFrame above
        seen: set[int | str] = set()
        for name in names:
            php_key = _php_key(name, strict=False)
            if php_key is None or php_key in seen:
                msg = (
                    f"column name {name!r} can't be a distinct PHP array key; "
                    "rename the columns first (e.g. df.columns = [str(c) for c in df.columns])"
                )
                raise ConversionError(msg)
            seen.add(php_key)

        state = _EncodeState(self.registry, strict=False)
        data: list[Json] = []
        for position, name in enumerate(names):
            values = _column_values(frame.iloc[:, position])  # type: ignore[attr-defined]  # a DataFrame
            data.append(_encode_items(values, state, 2))
            if state.refs:
                self.registry.release(state.refs)
                msg = (
                    f"column {name!r} holds {state.first_ref_type} values, which have no PHP "
                    f"equivalent; convert the column first, e.g. df[{name!r}].astype(str)"
                )
                raise ConversionError(msg)
        return _Raw({"columns": names, "data": data}, state.tagged)  # type: ignore[dict-item]  # names were checked to be str/int above

    def _next_batch(self, request: Request) -> object:
        iterator = self._target(request)
        if not isinstance(iterator, Iterator):
            msg = "next_batch needs an iterator reference"
            raise ProtocolError(msg)
        size = _require_int(request.get("size"), "size")
        items: list[object] = []
        for item in iterator:
            items.append(item)
            if len(items) >= size:
                return {"items": items, "done": False}
        return {"items": items, "done": True}


def _column_values(column: object) -> list[object]:
    """A pandas Series as plain Python values, with missing values as None."""
    kind = getattr(getattr(column, "dtype", None), "kind", None)
    values: list[object] = column.tolist()  # type: ignore[attr-defined]  # a pandas Series
    if kind in ("M", "m"):  # datetime64 (also with time zone) and timedelta64
        return [None if _is_missing(v) else v.isoformat() for v in values]  # type: ignore[attr-defined]  # Timestamp/Timedelta
    missing: list[bool] = column.isna().tolist()  # type: ignore[attr-defined]  # a pandas Series
    if any(missing):
        return [None if gap else value for value, gap in zip(values, missing, strict=True)]
    return values


def _is_missing(value: object) -> bool:
    # NaT is not equal to itself, like NaN.
    return value is None or value != value  # deliberate NaN/NaT check


_KINDS: Final = ("any", "module", "class", "callable")


def _kind(request: Request) -> str:
    kind = request.get("kind")
    if not isinstance(kind, str) or kind not in _KINDS:
        msg = f"field 'kind' must be one of {', '.join(_KINDS)}"
        raise ProtocolError(msg)
    return kind


def _is_kind(value: object, kind: str) -> bool:
    if kind == "module":
        return isinstance(value, types.ModuleType)
    if kind == "class":
        return isinstance(value, type)
    if kind == "callable":
        return callable(value)
    return True


def _text(request: Request, key: str) -> str:
    value = request.get(key)
    if not isinstance(value, str):
        msg = f"field {key!r} must be a string"
        raise ProtocolError(msg)
    return value


def _name(request: Request, key: str) -> str:
    value = _text(request, key)
    if value == "":
        msg = f"field {key!r} must not be empty"
        raise ProtocolError(msg)
    return value


def _error_response(
    kind: str, error: BaseException, output: io.StringIO, *, with_traceback: bool = False
) -> Response:
    details: dict[str, object] = {
        "kind": kind,
        "type": type_name(error),
        "message": str(error),
        "traceback": "",
    }
    if isinstance(error, PhpError):
        # Lets PHP rethrow the original PHP exception object.
        details["php_error"] = error._error_id
    if with_traceback:
        # Drop the worker's own frames so the traceback starts at user code.
        tb = error.__traceback__
        while tb is not None and tb.tb_frame.f_code.co_filename == __file__:
            tb = tb.tb_next
        details["traceback"] = "".join(traceback.format_exception(type(error), error, tb))
    return {"ok": False, "error": details, "out": output.getvalue()}


# --------------------------------------------------------------------------- #
# Transport
# --------------------------------------------------------------------------- #


def _isolate_stdio() -> None:
    """Keep user code away from the process's real stdin/stdout.

    The protocol runs on a socket, so stray output cannot corrupt it; this just
    makes the behaviour predictable: fd 1 is redirected to the log (fd 2), and
    stdin reads as empty.
    """
    os.dup2(2, 1)
    sys.stdout = sys.stderr
    devnull = os.open(os.devnull, os.O_RDONLY)
    os.dup2(devnull, 0)
    os.close(devnull)
    sys.stdin = io.StringIO("")


def _recv_exact(sock: socket.socket, size: int) -> bytes | None:
    data = _recv_buffer(sock, size)
    return None if data is None else bytes(data)


def _recv_buffer(sock: socket.socket, size: int) -> bytearray | None:
    """Exactly `size` bytes, or None when the connection closes first."""
    data = bytearray(size)
    view = memoryview(data)
    received = 0
    while received < size:
        count = sock.recv_into(view[received:], size - received)
        if not count:
            return None
        received += count
    return data


class Connection:
    """Length-prefixed JSON frames over the socket to PHP."""

    def __init__(self, sock: socket.socket, max_frame: int) -> None:
        self.sock = sock
        self.max_frame = max_frame

    def send(self, message: Response) -> None:
        """Send a response; one that is too large is replaced by an error response.

        Buffers under the ``_buffers`` key follow the JSON frame as binary frames.
        """
        buffers = cast("list[bytes]", message.pop("_buffers", []))
        if buffers:
            message["bin"] = len(buffers)
        body = json.dumps(message, ensure_ascii=False, allow_nan=False).encode("utf-8")
        size = max([len(body), *map(len, buffers)])
        if size > self.max_frame:
            buffers = []
            too_large = ProtocolError(
                f"the response is {size} bytes, above the {self.max_frame}-byte limit; "
                "keep the value in Python (use a reference) or raise max_message_bytes"
            )
            out = message.get("out")
            body = json.dumps(
                _error_response(
                    "too_large", too_large, io.StringIO(out if isinstance(out, str) else "")
                ),
                ensure_ascii=False,
            ).encode("utf-8")
        self.sock.sendall(HEADER.pack(len(body)) + body)
        for buffer in buffers:
            self.sock.sendall(HEADER.pack(len(buffer)))
            self.sock.sendall(buffer)

    def send_call(self, message: dict[str, object]) -> None:
        """Send a callback call; one that is too large raises ConversionError."""
        body = json.dumps(message, ensure_ascii=False, allow_nan=False).encode("utf-8")
        if len(body) > self.max_frame:
            msg = (
                f"the arguments of a PHP callback are {len(body)} bytes, above the "
                f"{self.max_frame}-byte limit; pass a reference instead"
            )
            raise ConversionError(msg)
        self.sock.sendall(HEADER.pack(len(body)) + body)

    def receive(self) -> Request | None:
        """The next frame from PHP, or None when PHP closed the connection."""
        header = _recv_exact(self.sock, HEADER.size)
        if header is None:
            return None
        (length,) = HEADER.unpack(header)
        if length > self.max_frame:
            msg = f"request of {length} bytes exceeds the limit"
            raise FatalFrameError(msg)
        body = _recv_exact(self.sock, length)
        if body is None:
            return None
        try:
            request = json.loads(body)
        except ValueError:
            msg = "request is not valid JSON"
            raise FatalFrameError(msg) from None
        if not isinstance(request, dict):
            msg = "request is not a JSON object"
            raise FatalFrameError(msg)
        if "bin" in request:
            request["_buffers"] = self._receive_binary(request.pop("bin"))
        return request

    def _receive_binary(self, count: object) -> list[bytearray]:
        if type(count) is not int or not 0 <= count <= MAX_BINARY_FRAMES:
            msg = "request announces an invalid number of binary frames"
            raise FatalFrameError(msg)
        buffers = []
        for _ in range(count):
            header = _recv_exact(self.sock, HEADER.size)
            if header is None:
                raise WorkerExit
            (length,) = HEADER.unpack(header)
            if length > self.max_frame:
                msg = f"binary frame of {length} bytes exceeds the limit"
                raise FatalFrameError(msg)
            buffer = _recv_buffer(self.sock, length)
            if buffer is None:
                raise WorkerExit
            buffers.append(buffer)
        return buffers


def serve(sock: socket.socket, token: str, max_frame: int) -> int:
    connection = Connection(sock, max_frame)
    session = Session(bridge=connection)
    connection.send(
        {
            "op": "hello",
            "token": token,
            "protocol": PROTOCOL_VERSION,
            "python": sys.version.split()[0],
            "pid": os.getpid(),
        }
    )
    try:
        while True:
            request = connection.receive()
            if request is None:
                return 0
            connection.send(session.handle(request))
            if request.get("op") == "shutdown":
                return 0
    except WorkerExit:
        return 0
    except FatalFrameError as error:
        sys.stderr.write(f"pyhp: {error}, stopping\n")
        return 2


def _authenticated(conn: socket.socket, token: str, timeout: float) -> bool:
    """The first frame on a new connection must be {"op": "auth", "token": <token>}."""
    conn.settimeout(max(0.01, min(timeout, AUTH_TIMEOUT)))
    try:
        header = _recv_exact(conn, HEADER.size)
        if header is None:
            return False
        (length,) = HEADER.unpack(header)
        if length > MAX_AUTH_FRAME:
            return False
        body = _recv_exact(conn, length)
        if body is None:
            return False
        message = json.loads(body)
    except (OSError, ValueError):  # timeouts, resets, invalid JSON or UTF-8
        return False
    offered = message.get("token") if isinstance(message, dict) else None
    return (
        isinstance(message, dict)
        and message.get("op") == "auth"
        and isinstance(offered, str)
        and hmac.compare_digest(offered.encode(), token.encode())
    )


def _accept_php(server: socket.socket, token: str, deadline: float) -> socket.socket | None:
    """Wait for PHP to connect and authenticate. Other connections are dropped."""
    while True:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            return None
        server.settimeout(remaining)
        try:
            conn, _ = server.accept()
        except TimeoutError:
            return None
        if _authenticated(conn, token, remaining):
            conn.settimeout(None)
            return conn
        conn.close()


def unsupported_python(version: tuple[int, ...]) -> str | None:
    """Why this Python can't run the worker, or None when it can."""
    if tuple(version[:2]) >= MIN_PYTHON:
        return None
    wanted = ".".join(map(str, MIN_PYTHON))
    found = ".".join(map(str, version[:3]))
    return f"PyHP needs Python {wanted} or newer, but this is Python {found}"


def listen() -> socket.socket:
    """A listening socket on a random loopback port that no other process can share."""
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        exclusive = getattr(socket, "SO_EXCLUSIVEADDRUSE", None)
        if exclusive is not None:  # Windows: stop other processes binding the same port
            server.setsockopt(socket.SOL_SOCKET, exclusive, 1)
        server.bind(("127.0.0.1", 0))
        server.listen()
    except OSError:
        server.close()
        raise
    return server


def main() -> int:
    problem = unsupported_python(sys.version_info[:3])
    if problem is not None:
        sys.stderr.write(f"pyhp: {problem}\n")
        return 4
    port_file = os.environ.pop("PYHP_PORT_FILE")
    token = os.environ.pop("PYHP_TOKEN")
    max_frame = int(os.environ.pop("PYHP_MAX_FRAME", str(DEFAULT_MAX_FRAME)))
    startup_timeout = float(os.environ.pop("PYHP_STARTUP_TIMEOUT", "10"))
    extra_paths = os.environ.pop("PYHP_PATHS", "")
    # Neither the working directory nor this script's folder is importable: a file
    # someone could drop there (random.py, json.py, ...) must never shadow a module.
    # Only Python's own paths and the "paths" option are.
    sys.path[:] = [p for p in sys.path if p and os.path.realpath(p) != _SCRIPT_DIR]
    for path in reversed([p for p in extra_paths.split(os.pathsep) if p]):
        sys.path.insert(0, path)
    _isolate_stdio()

    # The worker listens and PHP connects, not the other way round: on Windows,
    # PHP waits 500 ms whenever it closes a listening socket.
    with listen() as server:
        port = server.getsockname()[1]
        # The trailing newline tells PHP the number is complete.
        with open(port_file, "w", encoding="ascii") as handle:
            handle.write(f"{port}\n")
        sock = _accept_php(server, token, time.monotonic() + startup_timeout)
    if sock is None:
        sys.stderr.write("pyhp: PHP did not connect within the start-up timeout, stopping\n")
        return 3
    with sock:
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        return serve(sock, token, max_frame)


if __name__ == "__main__":
    sys.exit(main())
