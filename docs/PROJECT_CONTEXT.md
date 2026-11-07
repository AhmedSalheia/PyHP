# PyHP — Project Context and Development Roadmap

> Renamed from **PHPy** on 2026-11-07. Supersedes the original "PHPy — Project Context" document.

## Purpose of this document

This is the context for my second tool in the **Random Development Projects** Claude project. Keep it separate from xlcv and my other projects. Use it to guide planning, implementation, and future conversations. Update it when we confirm decisions; don't turn proposals into requirements without discussion.

## 1. The idea

**PyHP** lets PHP code use Python libraries (pandas and NumPy first) **in PHP syntax**, with a shared context between the two languages, so developers don't have to shuttle data back and forth by hand ("pass this to Python, expect that back"). Developers who don't know Python should be able to use Python libraries through PHP objects.

It exists until "a mad man writes pandas for PHP", and it should help PHP shine as more than a server-side scripting language. It should be a tool I enjoy building and developers enjoy using, with no costly, over-engineered infrastructure.

## 2. About me

- I primarily develop on Windows using VS Code.
- I work with PHP, Laravel, NativePHP, Python, SQLite, and automation.
- pandas and NumPy are major motivations for this project.
- I want practical implementation steps and explanations of meaningful decisions.
- It must be usable from ordinary PHP. Laravel integration can come later.
- My coding rules (TDD, strict typing, PHPStan max, mypy strict, 80% coverage) apply.

## 3. Positioning: why this exists next to swoole/phpy

[swoole/phpy](https://github.com/swoole/phpy) already embeds CPython in PHP through a C extension. It is much faster per call, and PyHP will not compete on speed. **Confirmed:** PyHP does **not** pursue in-process embedding. It differentiates by:

- **Zero setup:** `composer require`, no compiled extension. Works where extensions can't be installed (NativePHP, managed hosting, Windows desktops).
- **Crash isolation:** a Python crash raises a PHP exception instead of killing PHP.
- **Hard timeouts:** a stuck call can be killed.
- **Any interpreter or venv per session.**

Design rule that follows from this: **coarse-grained calls.** Work on whole DataFrames/arrays in a few calls; copy only small results to PHP.

## 4. Execution model (honest description)

A session is **one separate Python process** (the worker) plus one Python global namespace. PHP talks to it over a loopback TCP socket with length-prefixed JSON frames. Python objects stay in the worker; PHP holds proxies (IDs). Never describe this as Python embedded in PHP.

Why a socket instead of pipes: PHP on Windows can't `stream_select()` on pipes, so pipes give no timeouts there; sockets do on every OS. Also, printed output can never corrupt the protocol channel.

## 5. Confirmed decisions

| # | Decision | Date |
|---|---|---|
| D1 | Project name **PyHP** (was PHPy). Composer name placeholder `ahmedsalheia/pyhp`, namespace `PyHP\`. Check availability before publishing. | 2026-11-07 |
| D2 | Persistent worker + object proxies is the core of the project, not a later add-on. | 2026-11-07 |
| D3 | A session object exposes **`vars`** (global variables as PHP properties) and **`functions`** (global callables as PHP methods). **`classes`** may be added later. | 2026-11-07 |
| D4 | **Imports will be designed together** in a later discussion. The MVP has only a minimal `import($module, as:)`. | 2026-11-07 |
| D5 | No in-process embedding / native extension / FFI milestone. phpy covers that. | 2026-11-07 |
| D6 | `Py::exec()` no longer means "fresh process per call"; it runs in the shared default session. One-shot processes are not a feature for now. | 2026-11-07 (MVP) |

## 6. MVP — implemented (2026-11-07)

**Public API**

- `Py::session(array $overrides)`, `Py::default()`, `Py::config()`, `Py::configuration()`, `Py::reset()`, static shortcuts `Py::exec/execFile/eval/import/vars/functions`.
- `Session`: `exec`, `execFile`, `eval`, `import`, `$vars`, `$functions`, `takeOutput`, `log`, `info`, `isOpen`, `close`.
- `Vars`: `__get/__set/__isset/__unset`, `names()`, `ref()`.
- `Functions`: `__call` (named args → kwargs), `__get` (callable proxy), `__isset`. Global lookup falls back to builtins.
- `PyObject` proxy: attributes, method calls, `__invoke`, `ArrayAccess` (`[] =` → append), batched lazy `foreach`, `__toString`, release on destruct, no clone/serialize.
- Helpers: `Py::tuple`, `Py::slice`, `Py::dict`, `Py::len`, `Py::repr`, `Py::type`, `Py::toPhp`.
- `Config` (readonly; snake_case options array): python, timeout, startup_timeout, cwd, env, inherit_env, paths, output (Echo|Capture), max_message_bytes, log_file, iter_batch_size.
- Exceptions: PythonException, ConversionException, MessageTooLargeException (session survives); TimeoutException, WorkerCrashedException, SessionClosedException, ProtocolException (session closed); WorkerStartException; ConfigException.

**Conversion rule:** exact built-in plain data is copied (None, bool, int64, float incl. NaN/Inf, str, bytes, list, tuple, dict with str/int keys, NumPy scalars except datetime64/timedelta64). Everything else stays in Python as a proxy, per item inside containers.

**Worker:** `python/pyhp_worker.py`, standard library only, Python ≥ 3.10, protocol version 1.

## 7. Validation evidence (2026-11-07)

Run in a Linux cloud sandbox: PHP 8.3.6, Python 3.13.18, pandas 3.0.5, NumPy 2.5.3.

- Python worker: 70 pytest tests pass, 98% branch coverage (including the worker as a real subprocess), `ruff` clean, `mypy --strict` clean.
- PHP: 101 tests pass, including real pandas/NumPy workflows, about 89% line coverage (PCOV). **Caveat:** Packagist was unreachable from the sandbox, so the tests ran under a small Pest-compatible stand-in runner, not real Pest. **PHPStan and Pint have not been run yet.**
- All four examples run successfully.
- Measured: start-up ~37 ms; round trip ~70–100 µs; `import pandas` ~210 ms; 1M-row groupby via 4 proxy hops ~17 ms vs ~10 ms in one eval; copying 100k rows to PHP ~900 ms.
- **Windows (Ahmed's machine, 2026-11-07):** `composer check` passes: Pint clean, PHPStan level max clean (after 6 + 1 fixes), real Pest 101/101 passing (232 assertions) with 88.0% coverage (Xdebug), including the pandas/NumPy tests. Python worker: 70/70 pytest on Windows with Python 3.11.9, 98.5% coverage. Under Xdebug coverage each test takes ~0.7 s, mostly worker start-up; plain start-up time on Windows hasn't been measured yet.
- Windows-specific fix found by that run: a missing interpreter makes `proc_open()` raise a PHP warning on Windows. It's now caught and folded into `WorkerStartException`.
- **Windows performance (2026-11-07, PHP 8.4.26, Python 3.11.9 venv):** session start-up was ~650 ms because PHP on Windows waits 500 ms whenever it closes a listening socket (found with `benchmarks/startup-trace.php`). Fixed by reversing the connection: the worker listens on loopback and PHP connects and authenticates with the token. Start-up is now ~175 ms (Python alone ~120 ms, worker imports ~50 ms); round trip ~87 µs; copying 100k rows ~630 ms.
- **Memory (found on Windows, where `memory_limit` is 128 MB):** copying 100k rows peaked at 207 MB. Dicts now travel as plain JSON objects plus a `tagged` flag so plain results skip PHP's decode pass: peak 60 MB for a 53 MB result.
- Python 3.10.12 (the stated minimum): worker tests 70/70 in Ahmed's Linux VM.
- **macOS: not validated.** The CI workflow (`.github/workflows/ci.yml`, kept outside the folder because `.github` is protected for remote writes) runs the suite on Ubuntu and Windows.

## 8. Known limitations (summary; README has the full list)

No Python operators on proxies (use `gt`, `mul`, `__and__`, or `eval`). No PHP callbacks into Python. Output isn't streamed. Timeout kills the worker and its state. No automatic restart. Sessions can't persist across PHP-FPM requests. Descendant processes aren't killed. Tuples/empty containers lose their type in PHP. Not a sandbox.

## 9. Roadmap

1. ~~Verify on Windows~~ Done 2026-11-07 (see §7). Still to do: measure Windows start-up and round-trip times without Xdebug; push to GitHub with the CI workflow.
2. **Imports design session** (D4): `from x import y`, a `modules` namespace, caching.
3. **`classes` namespace** (D3).
4. **Bulk data path:** Arrow/Parquet for DataFrames instead of JSON rows; benchmark first.
5. **PHP callbacks** (`df.apply($closure)`): requires re-entrant requests from Python to PHP.
6. **Release prep:** license, Packagist name check, CHANGELOG, tag 0.1.0.
7. Later, only if wanted: Laravel integration (service provider, config file), a session pool for long-running workers.

## 10. How Claude should work with me

- Work on the current milestone and explain how it supports the vision.
- Separate confirmed decisions, recommendations, experiments, and future ideas.
- Ask only questions that materially affect the next step; otherwise recommend defaults.
- Provide complete files and exact commands, with Windows instructions.
- Verify current APIs in official documentation when relevant.
- Never claim code ran, tests passed, or a platform works without evidence.
- At milestone boundaries, update this document with decisions, implemented behaviour, validation, limitations, and the next step.
- Don't add Laravel coupling or install Python dependencies automatically, and don't expand scope without discussing it.

## References

- PHP `proc_open`: https://www.php.net/manual/en/function.proc-open.php
- PHP `stream_select` (Windows pipe limitation): https://www.php.net/manual/en/function.stream-select.php
- CPython embedding (background only): https://docs.python.org/3/extending/embedding.html
- swoole/phpy: https://github.com/swoole/phpy
