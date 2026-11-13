# 15. How PyHP works inside

## How it works inside

```
 PHP process                                     Python worker (python -u pyhp_worker.py)
┌──────────────────────────────┐               ┌───────────────────────────────────────┐
│ Py / Session / Vars /        │  127.0.0.1    │ Session                               │
│ Functions / PyObject         │◄─────────────►│  ├─ namespace (module "__pyhp__")     │
│   │                          │  TCP socket   │  ├─ registry: id → Python object      │
│   ├─ Codec: PHP ⇄ JSON       │  length-      │  ├─ codec: Python ⇄ JSON              │
│   └─ Worker: proc_open,      │  prefixed     │  └─ handlers: exec, eval, getattr,    │
│      socket, timeouts        │  JSON frames  │     call_method, getitem, iter, …     │
└──────────────────────────────┘               │ stdout/stderr ──► log file            │
                                               │ stdin ◄── /dev/null (NUL)             │
                                               └───────────────────────────────────────┘
```

1. **Start:** PHP starts `python -u pyhp_worker.py` with `proc_open()` and an argument array, with no shell involved. The token, limits and the path of a temporary *port file* are passed in environment variables.
2. **Handshake:** the worker listens on `127.0.0.1` (random port) and writes the port to the port file. PHP connects and sends `{"op": "auth", "token": …}`. The worker checks the token, closes its listener, and answers `hello` with the protocol version, which PHP checks. The worker listens rather than PHP because, on Windows, PHP waits 500 ms every time it closes a listening socket.
3. **Requests:** each frame is a 4-byte big-endian length plus UTF-8 JSON, e.g. `{"op": "call_method", "ref": 7, "name": "head", "args": [3], "kwargs": {}}`. The worker runs it, captures `print()` output, and answers `{"ok": true, "value": …, "out": "…"}` or `{"ok": false, "error": {"kind", "type", "message", "traceback"}}`.
4. **Values:** JSON natives travel as-is, and Python dicts travel as plain JSON objects, so PHP's `json_decode()` produces the final array directly. Every response carries a `tagged` flag; when it's `false` (no references, bytes or NaN/Inf anywhere in the value), PHP uses that array as-is, without a second pass or a second copy. Other values are tagged objects: `{"$t": "ref", "id": 7, "type": "pandas.DataFrame"}`, `{"$t": "bytes", "v": "<base64>"}`, `{"$t": "float", "v": "nan"}`, and `{"$t": "dict", "o": {…}}` for the rare Python dict that itself has a `"$t"` key. From PHP to Python, dicts travel as `{"$t": "dict", "v": [[key, value], …]}` so integer keys stay integers, plus `{"$t": "tuple", …}` and `{"$t": "slice", …}`.
5. **Releases:** IDs of freed proxies ride along on the next request (`"release": [7, 9]`).
6. **Timeouts:** PHP waits with `stream_select()` against one deadline per call. On expiry the worker is killed.
7. **Callbacks:** while Python handles a request it may send `{"op": "php_call", "seq": 1, "cb": 3, "args": [...], "kwargs": {...}}` instead of a response. PHP runs closure #3 (closures travel as `{"$t": "callback", "id": 3}`), pauses the deadline meanwhile, and answers `{"op": "php_return", "seq": 1, "ok": true, "value": ...}`. While Python waits for that answer, PHP may send ordinary requests (the closure calling Python), which Python serves first. Closures Python drops are reported in `"release_callbacks"`.

8. **Live output:** when a request asks for it (`"stream": true`, set in echo mode or with `onOutput`), the worker also sends `{"op": "output", "text": …}` frames while it runs, at each newline or flush, up to 16 K characters each. PHP hands them to the output handler and keeps waiting; the remainder travels in the response's `out`.

The protocol is versioned (`protocol: 3` since live output; 2 added callbacks). A mismatch between PHP and Python halves fails at the handshake. The next section describes every frame.

## The wire protocol, frame by frame

Protocol version: **4**. This is a reference for people working on PyHP itself. Both halves ship in the same package, so the protocol isn't part of the [compatibility promise](17-compatibility.md); it can change in any release, with the version number going up.

### Start-up

PHP starts `python -u pyhp_worker.py` with these environment variables, which the worker removes from `os.environ` before any user code runs:

| Variable | Meaning |
|---|---|
| `PYHP_PORT_FILE` | A temporary file the worker writes its port to, followed by a newline. |
| `PYHP_TOKEN` | A random secret both sides must prove they know. |
| `PYHP_STARTUP_TIMEOUT` | Seconds the worker waits for PHP to connect (`startup_timeout`). |
| `PYHP_MAX_FRAME` | Largest frame body in bytes (`max_message_bytes`). |
| `PYHP_PATHS` | Extra `sys.path` entries (`paths`), separated by the platform's path separator. |

The worker listens on `127.0.0.1` on a random port. PHP connects and the handshake is two frames:

| Frame | Direction | Fields |
|---|---|---|
| `auth` | PHP → Python | `token`. Must be the first frame, at most 4,096 bytes, within 5 s. Connections that don't authenticate are dropped and the worker keeps waiting. After PHP authenticates, the worker stops listening. |
| `hello` | Python → PHP | `token` (the same secret, so PHP knows it reached its own worker), `protocol`, `python` (version), `pid`. PHP refuses a different `protocol` with a clear `WorkerStartException`. |

### Frames

Every frame is a 4-byte big-endian unsigned length followed by that many bytes of UTF-8 JSON, always an object. A message with a `bin` field (an integer) is followed by that many **binary frames**: the same length prefix, then raw bytes, the data of the NumPy arrays in the message (see `ndarray` in [Values](#values)). They're sent right after the JSON frame, in the order the `buf` indexes count. In Python the received frames are attached to the request as `_buffers`, which never goes on the wire.

A frame, JSON or binary, longer than `max_message_bytes` is never sent:

* A request PHP can't send throws `MessageTooLargeException` before anything is written.
* A response that is too large is replaced by a `too_large` error response.
* Callback arguments that are too large raise `ConversionError` in Python.

A frame the worker can't read (too long, invalid JSON, not an object, a `bin` count that isn't an integer from 0 to 65,536) ends the session: the worker writes the reason to its log and exits with code 2. It exits with 0 when PHP closes the connection or sends `shutdown`, and with 3 when PHP never connected.

### Requests (PHP → Python)

Every request has `op` and may have:

* `release`: a list of reference ids PHP no longer needs. They're freed before the operation runs.
* `stream`: `true` to receive printed output while the call runs (see `output` below).

`ref` is a reference id. `name` is a non-empty string. `args` is a list of values and `kwargs` an object of name → value; both default to empty. Values use the tags in [Values](#values).

| `op` value | Fields | Result |
|---|---|---|
| `ping` | | `{protocol, python, executable, objects, callbacks}` |
| `shutdown` | | `null`; the worker exits after answering |
| `exec` | `code` | `null` |
| `exec_file` | `path` | `null`; runs the file in the session namespace |
| `eval` | `expr` | the expression's value |
| `import` | `module`, `as` (string or null), `from` (string or null) | what was imported (`null` for `*`) |
| `get_var` | `name` | the global's value |
| `get_var_ref` | `name` | the global, always as a reference |
| `set_var` | `name`, `value` | `null` |
| `del_var` | `name`, `missing_ok` (bool) | `null` |
| `has_var` | `name` | bool |
| `var_names` | | list of global names not starting with `__` |
| `get_global` | `name`, `kind` | the global as a reference, if it is of that kind |
| `has_global` | `name`, `kind` | bool |
| `global_names` | `kind` | list of global names of that kind |
| `is_callable_global` | `name` | bool |
| `call_global` | `name`, `args`, `kwargs`, optional `kind` | the call's result |
| `getattr` | `ref`, `name` | the attribute's value |
| `getattr_ref` | `ref`, `name` | the attribute, always as a reference |
| `setattr` | `ref`, `name`, `value` | `null` |
| `delattr` | `ref`, `name` | `null` |
| `hasattr` | `ref`, `name` | bool |
| `getitem` | `ref`, `key` | `obj[key]` |
| `setitem` | `ref`, `key`, `value` | `null` |
| `delitem` | `ref`, `key` | `null` |
| `hasitem` | `ref`, `key` | bool: the key exists and its value isn't `None` (PHP's `isset()`) |
| `call` | `ref`, `args`, `kwargs` | `obj(*args, **kwargs)` |
| `call_method` | `ref`, `name`, `args`, `kwargs` | `obj.name(*args, **kwargs)`; the attribute must be callable |
| `str` | `ref` | `str(obj)` |
| `repr` | `ref` | `repr(obj)` |
| `len` | `ref` | `len(obj)` |
| `type` | `ref` | the type's qualified name, e.g. `pandas.DataFrame` |
| `to_php` | `ref` | a strict deep copy: fails instead of returning references |
| `iter` | `ref` | a reference to `iter(obj)` |
| `next_batch` | `ref` (an iterator), `size` | `{items: [...], done: bool}` |
| `table` | `ref` (a DataFrame) | `{columns: [...], data: [[...], ...]}`, one list per column |

`kind` is one of `any`, `module`, `class`, `callable`. Builtins count as globals, as in Python.

### Responses (Python → PHP)

Success:

```json
{"ok": true, "value": ..., "tagged": false, "out": "printed text"}
```

`tagged: false` means `value` contains no tagged objects, so PHP uses the decoded JSON as-is without a second pass. `out` is what the call printed and hadn't already been sent in `output` frames.

Failure:

```json
{"ok": false, "out": "...", "error": {"kind": "python", "type": "KeyError", "message": "'x'", "traceback": "..."}}
```

| `kind` | Cause | PHP throws |
|---|---|---|
| `python` | User code raised (`traceback` filled in, worker frames removed) | `PythonException`; or the original PHP exception when `php_error` names one (an uncaught `PhpError`) |
| `conversion` | A value can't cross in that direction | `ConversionException` |
| `too_large` | The response was larger than `max_message_bytes` | `MessageTooLargeException` |
| `stale_ref` | A reference id the worker doesn't know | `ProtocolException` |
| `protocol` | A malformed request (unknown `op`, missing field) | `ProtocolException` |

Responses may also carry `release_callbacks` (see below).

### Frames during a request

While a request runs, the worker may send these before its response:

| Frame | Direction | Fields |
|---|---|---|
| `output` | Python → PHP | `text`: printed output, sent at each newline or flush (at most 16 K characters per frame) when the request had `stream: true`. PHP passes it to the output handler or echoes it and keeps waiting. |
| `php_call` | Python → PHP | `seq` (counts up per session), `cb` (callback id), `args`, `kwargs`, `tagged`. Python calls a PHP closure and waits. |
| `php_return` | PHP → Python | `seq`, `ok`, and `value`; or `ok: false` and `error: {class, message, file, line, id}`, which Python raises as `pyhp.PhpError`. |

While Python waits for `php_return`, PHP may send ordinary requests (the closure calling Python again); Python answers each one before going back to waiting. Calls nest up to 32 levels deep. Time PHP spends running a closure or handling output doesn't count against `timeout`.

Responses and `php_call` frames may carry `release_callbacks`: callback ids Python no longer holds, so PHP can let those closures go. Requests and `php_return` frames may carry `release`.

### Values

JSON's own types travel as they are: `null`, booleans, numbers, strings, lists. A Python `dict` travels as a JSON object; PHP's `json_decode()` turns it straight into an array. Everything else is a tagged object, with the tag in `$t`:

| Tag | Direction | Meaning |
|---|---|---|
| `{"$t": "ref", "id": 7, "type": "pandas.DataFrame"}` | both | A reference to an object in the worker (`type` only from Python). |
| `{"$t": "callback", "id": 3}` | both | A PHP closure; Python calls it with `php_call`. |
| `{"$t": "vectorize", "fn": {"$t": "callback", ...}, "batch": 1000}` | PHP → Python | `Py::vectorize()`: a closure Python calls once per batch. |
| `{"$t": "dict", "v": [[key, value], ...]}` | PHP → Python | A PHP array with keys, as pairs, so integer keys stay integers. |
| `{"$t": "dict", "o": {...}}` | Python → PHP | A dict that itself has a `"$t"` key, wrapped so it isn't read as a tag. |
| `{"$t": "tuple", "v": [...]}` | PHP → Python | `Py::tuple()` |
| `{"$t": "slice", "v": [start, stop, step]}` | PHP → Python | `Py::slice()` |
| `{"$t": "bytes", "v": "<base64>"}` | both | `bytes` in Python; a PHP string that isn't valid UTF-8. |
| `{"$t": "float", "v": "nan"}` | both | `nan`, `inf` or `-inf`, which JSON can't carry. |
| `{"$t": "ndarray", "dtype": "f8", "shape": [1000, 3], "buf": 0}` | both | A NumPy array whose data is binary frame number `buf` of the message: little-endian, C order (row after row). `dtype` is one of `b1`, `i1`, `i2`, `i4`, `i8`, `u1`, `u2`, `u4`, `f4`, `f8`, NumPy's own codes. From Python only in `to_php` results; other dtypes there go value by value (`uint64` as `i8` when every value fits, `float16` as `f4`, `longdouble` as `f8`). From PHP for `Py::ndarray()`; Python gets a writable array over the received bytes, without a copy. |

Values nest at most 256 levels deep.

### Version history

| Version | PyHP | Change |
|---|---|---|
| 4 | 1.0.0 | Binary frames (`bin`) and `ndarray` values: NumPy arrays as raw bytes. |
| 3 | 0.4.0 | Live output: `stream` and `output` frames; `vectorize` values. |
| 2 | 0.1.0 | PHP callbacks: `php_call`, `php_return`, `callback` values, `release_callbacks`. |
| 1 | before 0.1.0 | The first protocol. |

## PyHP vs. swoole/phpy

[swoole/phpy](https://github.com/swoole/phpy) embeds CPython inside PHP through a C extension: PHP and Python share one process, and calls are C function calls.

| | PyHP | swoole/phpy |
|---|---|---|
| Install | `composer require` | Compile and enable a PHP extension (and build against your Python) |
| Speed per call | ~0.1 ms round trip | Far faster (direct C calls) |
| Python crash | PHP gets an exception, keeps running | Takes the PHP process down |
| Hard timeouts | Yes (kills the worker) | No clean way to stop a running call |
| Python environment | Any interpreter/venv per session | The one it was built and linked with |
| Data | Copied through JSON or referenced by ID | Shared in memory |

**Choose phpy** when you need maximum speed for fine-grained interop and you control the server build. **Choose PyHP** when you want zero setup, crash isolation, timeouts, per-project virtual environments, or you can't install extensions (NativePHP, managed hosting, Windows desktops).

---

← [Limitations](14-limitations.md) · [Contents](README.md) · [Development](16-development.md) →
