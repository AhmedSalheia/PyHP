# Changelog

All notable changes to PyHP are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

Every change is added under **[@dev]** as soon as it's made; when a version is released, those entries move under its number.

## [@dev]

### Added
- `ROADMAP.md`: the plan for v0.3.0, v0.4.0 and the goals for v1.0.0.

## [0.2.0] - 2026-11-07

### Added
- `vendor/bin/pyhp setup`: creates the project's Python virtual environment and installs the packages listed under `extra.pyhp.requirements` in `composer.json` (or in `requirements.txt`). Uses uv when installed, which also downloads the requested Python version; otherwise the system Python with venv and pip. Safe to run again; `--python`, `--venv`, `--no-uv` and `--dry-run` options.
- `Py::ref($object, 'attribute')`: a live proxy to an attribute instead of a copy, so dicts and lists held by Python objects can be changed in place (`Py::ref($doc, 'user_data')['k'] = 'v'`).
- `Config::detectPython()`: PyHP now finds the interpreter by itself when the `python` option isn't set: the `PYHP_PYTHON` environment variable, then the Composer project's `.venv` (or `extra.pyhp.venv`), then `python`/`python3`.
- Examples `08_standard_library.php` (difflib, statistics, itertools, fractions/decimal, Counter, ipaddress), `09_matplotlib_charts.php`, `10_pillow_images.php` and `11_scikit_learn.php`, with optional test suites that skip when the library isn't installed; README section "More libraries in practice".
- `requirements-dev.txt`, referenced from PyHP's own `extra.pyhp`: `php bin/pyhp setup` in a clone installs everything the examples, optional tests and Python checks need.
- `vendor/bin/pyhp info`: shows PyHP and PHP details (version, binary, OS, php.ini, whether `proc_open()` is allowed), the project's Python settings, the interpreter PyHP will use and why, its version, the venv, uv, every required package with its installed version, and a live worker test; lists problems with the command that fixes them and exits 1 when there are any.
- `Config::locatePython()` and `PythonSource`: the interpreter PyHP would pick, the reason, and the project's venv folder.

### Changed
- The examples and the test suite use the interpreter PyHP detects (the repository's `.venv` after `php bin/pyhp setup`) instead of requiring `PYHP_PYTHON` / `PYHP_TEST_PYTHON`; both variables still work.
- Sessions without a `python` option now use `PYHP_PYTHON`, then the project's `.venv` (or `extra.pyhp.venv`), before falling back to `python`/`python3`. Projects that relied on the system interpreter while having a `.venv` in the project root now get the venv's interpreter.
- The documentation moved from one long README into numbered files in `docs/` (read in order, starting with `docs/01-introduction.md`). The README is now a short overview with examples, contribution notes and project information.
- The changelog collects unreleased changes under **[@dev]** instead of "Unreleased".
- CI installs `requirements-dev.txt` on Linux, so every optional library suite runs there.

### Fixed
- The pandas test accepts the DataFrame type name of every pandas version (pandas 3.1 reports `pandas.core.frame.DataFrame`, 3.0 reported `pandas.DataFrame`).

## [0.1.1] - 2026-11-07

### Changed
- The Composer package is now `pyhp/pyhp` (was `ahmedsalheia/pyhp`) and the repository lives at https://github.com/pyhp-php/pyhp. The PHP namespace stays `PyHP\`, so only `composer.json` changes: `composer remove ahmedsalheia/pyhp && composer require pyhp/pyhp`.

## [0.1.0] - 2026-11-07

First public release.

### Added
- Persistent Python worker (stdlib only, Python 3.10+) that PHP connects to over loopback, authenticated with a per-session token. Wire protocol version 2.
- `Session` with `exec`, `execFile`, `eval`, `import`, `vars`, `functions`, `classes`, `modules`, output capture and log access; `Py` static facade with a default session.
- `import()` that follows Python's import statement: `from:`, `as:`, star imports.
- `PyObject` proxies: attributes, methods with keyword arguments, calls, item access, lazy batched iteration, string conversion, automatic release.
- Value helpers `Py::tuple/slice/dict` and `Py::len/repr/type/toPhp`.
- Conversion rules between PHP and Python values, with fast paths for plain data (see README).
- `Py::rows($df)` and `Py::columns($df)`: copy a pandas DataFrame column by column, with missing values as `null` and dates as ISO strings (100k rows × 4 columns in ~180–240 ms).
- PHP callbacks: PHP closures, including first-class callables such as `ucfirst(...)`, can be passed to Python and called from it, with keyword arguments as named arguments. Callbacks may call Python again (up to 32 levels). An uncaught exception from a callback is rethrown in PHP as the original object; Python can catch it as `pyhp.PhpError`. Time spent in callbacks doesn't count against `timeout`.
- Exception hierarchy, timeouts, crash detection, message size limits.
- Examples: basics, vars/functions, pandas, NumPy, callbacks, SymPy, spaCy; overhead and start-up benchmarks.
- `studies/php-callbacks.md`: the feasibility study behind PHP callbacks.
- Git hooks: `pre-commit` formats, lints and type-checks staged files; `pre-push` blocks direct pushes to `main`/`develop`.

### Verified
- CI on Linux, Windows and macOS with PHP 8.2 / 8.4 and Python 3.10 / 3.13: Pest (80% coverage gate), PHPStan max, Pint, pytest, mypy --strict, ruff.
- SymPy and spaCy suites on Linux; pandas and NumPy on all three systems.

[@dev]: https://github.com/pyhp-php/pyhp/compare/v0.2.0...develop
[0.2.0]: https://github.com/pyhp-php/pyhp/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pyhp-php/pyhp/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pyhp-php/pyhp/releases/tag/v0.1.0
