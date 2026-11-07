# Changelog

All notable changes to PyHP are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Persistent Python worker over a loopback socket with a token handshake and protocol version 1.
- `Session` with `exec`, `execFile`, `eval`, `import`, `vars`, `functions`, output capture and log access.
- `PyObject` proxies: attributes, methods with keyword arguments, calls, item access, lazy iteration, string conversion, automatic release.
- `Py` static facade, `Py::tuple/slice/dict` value helpers, `Py::len/repr/type/toPhp`.
- Conversion rules between PHP and Python values (see README).
- Exception hierarchy, timeouts, crash detection, message size limits.
- Examples for basics, vars/functions, pandas and NumPy; overhead benchmark.

- `import()` follows Python's import statement: `from:` (`from datetime import time`), `as:`, star imports, and the imported name is always bound in the session.
- `$py->modules`: every module bound in the session, with `names()` and `isset()`.
- `$py->classes`: create instances of Python classes (`$py->classes->Invoice(...)`), get class proxies, `names()`; refuses non-classes.
- `Py::rows($df)` and `Py::columns($df)`: copy a pandas DataFrame column by column, 2.5–3.5× faster than `to_dict('records')`, with missing values as `null` and dates as ISO strings (100k rows × 4 columns: ~240 ms / ~180 ms instead of ~610 ms; columns peak at 16 MB instead of 60 MB).

- `studies/php-callbacks.md`: feasibility study and integration plan for calling PHP closures from Python.

- PHP callbacks: PHP closures (including `strlen(...)`-style first-class callables) can be passed to Python and called from it, with keyword arguments as named arguments. Callbacks may call Python again (up to 32 levels). An uncaught exception from a callback is rethrown in PHP as the original object; Python can catch it as `pyhp.PhpError`. Time spent in PHP callbacks doesn't count against `timeout`. Callbacks only run on the thread serving PHP, while PHP waits. Protocol version 2.
- `examples/05_callbacks.php`.

- `examples/06_sympy.php` and `examples/07_spacy_nlp.php`, with optional Pest suites (`SympyTest`, `SpacyTest`) that skip when the library or spaCy model isn't installed; README section "SymPy and spaCy in practice".

- `.githooks/pre-commit`: formats (Pint, ruff format), lints (ruff check) and type-checks (PHPStan, mypy) staged files before every commit.

### Changed
- Faster conversion of Python results: plain scalars skip per-value work and dict key sets are validated once per shape (records ~25% faster, flat lists ~4× faster), with identical output.
- Python dicts now travel to PHP as plain JSON objects, and each response says whether it contains any tagged value. Plain results skip PHP's decode pass: copying 100k rows × 4 columns dropped from a 207 MB to a 60 MB memory peak, and from ~1.6 s to ~0.65 s (Linux).

### Fixed
- CI on Windows: text files are now always checked out with LF line endings (`.gitattributes`), so Pint passes on Windows runners.
- README: a stale copy of the old performance section was left inside the new one.
- Windows start-up took ~650 ms because PHP waits 500 ms whenever it closes a listening socket. The worker now listens (loopback only, until PHP has connected) and PHP connects as a client, authenticating with the token. Workers that nobody connects to exit after `startup_timeout`.
- A failed start no longer leaks the socket or the temporary log file.
- A dict that has to stay in Python because its keys collide in PHP no longer leaves references to its values in the worker's registry.
- On Windows, a missing interpreter no longer leaks a `proc_open()` warning to the application's error handler.

### Verified
- Linux: PHP 8.3 / Python 3.13 / pandas 3.0 / NumPy 2.5.
- Windows: Pest 101/101 (88% coverage), PHPStan max, Pint; worker tests on Python 3.11.
