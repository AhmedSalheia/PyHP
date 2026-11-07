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

A session is **one separate Python process** (the worker) plus one Python global namespace. The worker listens on a loopback TCP socket; PHP connects and authenticates with a one-time token, then they exchange length-prefixed JSON frames. Python objects stay in the worker; PHP holds proxies (IDs). While Python handles a request it may call PHP closures (callbacks) over the same socket. Never describe this as Python embedded in PHP.

Why a socket instead of pipes: PHP on Windows can't `stream_select()` on pipes, so pipes give no timeouts there; sockets do on every OS. Printed output can never corrupt the protocol channel. Why Python listens rather than PHP: PHP on Windows waits 500 ms whenever it closes a listening socket.

## 5. Confirmed decisions

| # | Decision | Date |
|---|---|---|
| D1 | Project name **PyHP** (was PHPy). Composer name placeholder `ahmedsalheia/pyhp`, namespace `PyHP\`. Check availability before publishing. | 2026-11-07 |
| D2 | Persistent worker + object proxies is the core of the project, not a later add-on. | 2026-11-07 |
| D3 | A session object exposes **`vars`** (global variables as PHP properties) and **`functions`** (global callables as PHP methods). **`classes`** may be added later. | 2026-11-07 |
| D4 | **Imports will be designed together** in a later discussion. The MVP has only a minimal `import($module, as:)`. (Superseded by D7.) | 2026-11-07 |
| D5 | No in-process embedding / native extension / FFI milestone. phpy covers that. | 2026-11-07 |
| D6 | `Py::exec()` no longer means "fresh process per call"; it runs in the shared default session. One-shot processes are not a feature for now. | 2026-11-07 (MVP) |

### Decisions of 2026-11-07 (after the MVP)

| # | Decision |
|---|---|
| D7 | `import()` mirrors Python's import statement: `as:`, `from:`, star imports, submodules; the imported name is always bound in the session. It returns what was imported (proxy or plain value). |
| D8 | `$py->modules` is a view of the modules bound in the session (from `import()` or `exec()`), with `names()`/`isset()`. It never imports implicitly. Python itself caches modules per session (`sys.modules`); PHP doesn't cache proxies. |
| D9 | `$py->classes` creates instances and refuses non-classes; `functions` keeps accepting any callable. |
| D10 | No Gitea. The repo lives on GitHub (Ahmed's account), with CI on GitHub Actions. Ahmed's general GitFlow skill still mentions Gitea; for PyHP, GitHub is the only remote. |
| D11 | Faster bulk data only where it adds nothing for users to install: no Arrow (needs a PHP extension). Done: faster conversion walk plus `Py::rows()`/`Py::columns()`. |
| D12 | PHP callbacks: feasibility study first (`studies/php-callbacks.md`); build only after Ahmed answers its open questions. (Answered: D16–D21.) |
| D13 | First release (0.1.0) postponed until Ahmed has reviewed the code. |
| D14 | macOS: tested through GitHub Actions `macos-latest` runners (Ahmed has no Mac). Added to CI on 2026-11-07; all macOS jobs passed on the first run. |
| D15 | License: **MIT** (`LICENSE`, `composer.json`). Merged. |
| D16 | PHP callbacks ship in v1 (before 0.1.0). |
| D17 | Only `Closure` objects become Python callables; callable strings/arrays stay data. |
| D18 | The call's deadline pauses while PHP runs a callback. |
| D19 | An uncaught callback exception is rethrown in PHP as the original object; Python sees `pyhp.PhpError`. |
| D20 | Callback nesting limit: 32 levels, enforced in Python (`RecursionError`); to be revisited with real use. |
| D21 | Callbacks only on the thread serving PHP, while PHP waits on a call; background-thread callbacks are out of scope for v1. |
| D22 | SymPy and spaCy examples with optional Pest suites; CI installs them on Linux only. Merged (PR #11). |
| D23 | 0.1.0 is released through `release/0.1.0` → `main`; Ahmed's code review happens on that PR, and review fixes are committed to the release branch. |

## 6. Implemented (as of 2026-11-07)

**Public API**

- `Py::session(array $overrides)`, `Py::default()`, `Py::config()`, `Py::configuration()`, `Py::reset()`, static shortcuts `Py::exec/execFile/eval/import/vars/functions/modules/classes`.
- `Session`: `exec`, `execFile`, `eval`, `import(module, as:, from:)`, `$vars`, `$functions`, `$modules`, `$classes`, `takeOutput`, `log`, `info`, `isOpen`, `close`.
- `Vars`: `__get/__set/__isset/__unset`, `names()`, `ref()`.
- `Functions`: `__call` (named args → kwargs), `__get` (callable proxy), `__isset`. Global lookup falls back to builtins.
- `Modules`, `Classes`: views of the session namespace filtered by kind, with `names()`.
- `PyObject` proxy: attributes, method calls, `__invoke`, `ArrayAccess` (`[] =` → append), batched lazy `foreach`, `__toString`, release on destruct, no clone/serialize.
- Helpers: `Py::tuple`, `Py::slice`, `Py::dict`, `Py::len`, `Py::repr`, `Py::type`, `Py::toPhp`, `Py::rows`, `Py::columns`.
- PHP closures as Python callables (D16–D21).
- `Config` (readonly; snake_case options array): python, timeout, startup_timeout, cwd, env, inherit_env, paths, output (Echo|Capture), max_message_bytes, log_file, iter_batch_size.
- Exceptions: PythonException, ConversionException, MessageTooLargeException (session survives); TimeoutException, WorkerCrashedException, SessionClosedException, ProtocolException (session closed); WorkerStartException; ConfigException. A callback's own exception is rethrown as is.

**Conversion rule:** exact built-in plain data is copied (None, bool, int64, float incl. NaN/Inf, str, bytes, list, tuple, dict with str/int keys, NumPy scalars except datetime64/timedelta64). Everything else stays in Python as a proxy, per item inside containers. PHP closures become `pyhp.PhpCallable` and come back as the same `Closure`.

**Worker:** `python/pyhp_worker.py`, standard library only, Python ≥ 3.10, **protocol version 2** (callbacks).

## 7. Validation evidence

- **Linux sandbox (PHP 8.3.6, Python 3.13.18, pandas 3.0.5, NumPy 2.5.3):** Python worker 117 tests, 97% branch coverage, `ruff` and `mypy --strict` clean; PHP 140 tests, ~90% line coverage. PHP tests ran under a Pest-compatible stand-in (Packagist is unreachable there); real Pest, PHPStan and Pint run on Ahmed's machine and in CI.
- **Windows (Ahmed's machine, PHP 8.4.26, Python 3.11.9):** `composer check` passed (Pint, PHPStan max, real Pest, 88% coverage) for everything up to the table export; worker tests passed. Callbacks still to be verified there.
- **CI (GitHub Actions):** Python 3.10/3.13 and PHP 8.2/8.4 on Ubuntu, Windows and macOS. All macOS and Linux jobs passed on the first macOS run; Windows PHP failed only on Pint line endings, fixed by `.gitattributes` (`* text=auto eol=lf`).
- **Python 3.10.12** (the stated minimum): worker tests pass in Ahmed's Linux VM.
- **Performance:** start-up ~36 ms Linux / ~175 ms Windows (was ~650 ms before reversing the connection); round trip ~65–100 µs; one PHP callback ~65 µs (same as a call); `Series.apply` over 10k values with a PHP closure ~665 ms vs `pipe()` ~0.7 ms; copying 100k rows × 4 columns: `to_dict('records')` ~610 ms, `Py::rows()` ~240 ms, `Py::columns()` ~180 ms (16 MB peak).

## 8. Known limitations (summary; README has the full list)

No Python operators on proxies (use `gt`, `mul`, `__and__`, or `eval`). Callbacks: one round trip each, same thread only, 32 levels. Output isn't streamed. Timeout kills the worker and its state. No automatic restart. Sessions can't persist across PHP-FPM requests. Descendant processes aren't killed. Tuples/empty containers lose their type in PHP. Not a sandbox.

## 9. Roadmap

Done on 2026-11-07: Windows validation and fixes, imports, `modules`, `classes`, faster bulk data, callbacks study and implementation, MIT license, macOS CI, Windows CI line endings.

Also done: SymPy and spaCy examples and tests (PR #11), pre-commit hook, CI actions on v6.

Next:

1. **Release 0.1.0** (D23): Ahmed reviews and merges `release/0.1.0` into `main`, tags `v0.1.0`, submits the package to Packagist, back-merges `main` into `develop`.
2. After 0.1.0: `Py::ref($object, 'attribute')` for writing into attributes that would otherwise be copied; lossless conversion of integer-like numbers (SymPy `Integer`); more library examples.
3. Later, only if wanted: Laravel integration (service provider, config file), a session pool for long-running workers, `Py::vectorize()` for row-wise PHP callbacks in batches, a thread-safe callback mode.

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
