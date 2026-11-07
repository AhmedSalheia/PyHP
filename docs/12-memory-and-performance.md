# 12. Memory and performance

## Object lifetime and memory

* Each proxy keeps its Python object alive. When PHP frees the proxy (it goes out of scope, you `unset()` it, or the chain's temporary finishes), PyHP queues a release, which is **sent with the next request**. Destructors never wait on Python.
* Temporaries in a chain like `$df->groupby('g')['v']->sum()` are released automatically.
* Fetching the same Python object twice gives two proxies and two registry entries. Both are released independently, and the object lives until Python has no references left.
* **Leaks happen when you keep proxies around:** storing thousands of proxies in a long-lived PHP array keeps thousands of Python objects alive. `$py->info()['objects']` shows how many proxies the worker is holding.
* `close()` frees everything in the worker. Proxies that survive their session throw `SessionClosedException` when used.
* A `Session` isn't destroyed while any of its proxies still exist, because proxies reference it. Call `close()` explicitly when you're done instead of relying on destructors.
* On `close()`, PyHP closes the socket, waits up to 2 s for Python to exit cleanly, then kills it.

## Performance

Measured with [`benchmarks/overhead.php`](../benchmarks/overhead.php). Run it yourself, without Xdebug coverage: `php benchmarks/overhead.php path\to\python.exe`.

| Operation | Linux (PHP 8.3, Python 3.13, 2 vCPU) | Windows (PHP 8.4, Python 3.11 venv) |
|---|---|---|
| Start a session (no imports) | ~36 ms | ~175 ms (Python alone needs ~120 ms there) |
| One round trip (`eval('1')`) | ~74 µs | ~87 µs |
| Attribute read on a proxy | ~63 µs | ~76 µs |
| `import pandas` (first time in a session) | ~190 ms | ~330 ms |
| 1,000,000-row `groupby().mean().to_dict()` via 4 proxy calls | ~16 ms | ~16 ms |
| …the same as one `eval()` | ~9 ms | ~13 ms |
| `close()` with pandas loaded | ~72 ms | ~73 ms |

Copying a 100,000-row × 4-column DataFrame into PHP (Linux):

| Method | Time | PHP memory peak | PHP result |
|---|---|---|---|
| `$df->to_dict('records')` | ~610 ms | ~60 MB | list of rows, 53 MB |
| [`Py::rows($df)`](08-pandas-and-numpy.md#copying-tables-into-php) | ~240 ms | ~49 MB | list of rows, 41 MB |
| [`Py::columns($df)`](08-pandas-and-numpy.md#copying-tables-into-php) | ~180 ms | ~16 MB | one list per column, 11 MB |

What that means:

* **Per-call overhead is negligible for DataFrame-level work.** Going through 4 proxies instead of one Python expression costs a few milliseconds on a 1M-row groupby.
* **Per-call overhead dominates chatty code.** 10,000 attribute reads ≈ 1 s. Don't loop over rows from PHP; let pandas/NumPy do it, or write the loop in Python.
* **Copying is the expensive part, in time and in memory.** Data travels as JSON, and PHP arrays are much bigger than a DataFrame. Use `Py::rows()`/`Py::columns()` for tables (2.5–3.5× faster than `to_dict('records')`; columns need a quarter of the memory), copy summaries rather than datasets, and for very large outputs write a file from Python (`to_csv`, `to_parquet`). PHP's default `memory_limit` of 128 MB (Windows `php.ini`) holds roughly 250k rows × 4 columns as rows, or over 1M as columns.
* **Start-up and imports are paid per session.** Reuse a session for many calls instead of creating one per call. Python itself starts more slowly on Windows (~120 ms on the test machine vs ~13 ms on Linux).

The per-call figure is in line with other process-based bridges. In-process embedding (swoole/phpy) is far faster per call; see [the comparison](15-internals.md#pyhp-vs-swoolephpy).

**Why not Apache Arrow/Parquet for transfers?** Reading Arrow in PHP needs a compiled extension or a slow pure-PHP reader, which would break PyHP's "just `composer require`" promise. Column-wise JSON plus a faster conversion walk gets most of the gain with nothing extra to install. If you need Arrow/Parquet, write the file from Python (`$df->to_parquet($path)`) and read it with your own tools.

---

← [Output and errors](11-output-and-errors.md) · [Contents](README.md) · [Deployment, security and platforms](13-deployment-and-security.md) →
