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

The protocol is versioned (`protocol: 3` since live output; 2 added callbacks). A mismatch between PHP and Python halves fails at the handshake.

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
