# Changelog

All notable changes to PyHP are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

Every change is added under **[@dev]** as soon as it's made; when a version is released, those entries move under its number.

## [@dev]

### Added
- CI runs on ARM: the worker and the PHP library on Linux on ARM (`ubuntu-24.04-arm`) as required checks, plus the `pyhp setup` smoke tests there; Windows on ARM (`windows-11-arm`, with x64 PHP under emulation, as users run it) as a non-blocking job for now. macOS was already Apple Silicon.
- `pyhp info --json`: the same report as one JSON document (`format: 1`) for scripts, CI and support tools, with the same exit code. Its fields are covered by the compatibility promise: new ones can be added, existing ones keep their meaning. See [The pyhp command](docs/03-command-line.md#pyhp-info---json).
- Mutation testing: `composer mutate` (Pest's built-in `--mutate`) for the PHP library and `mutmut` for the Python worker, configured in `composer.json` and `pyproject.toml`, and a `Mutation testing` workflow that runs both every Monday or on demand. A first run on the worker killed 74% of the mutants; 73 new tests for exact limits (64-bit integers, nesting depth, frame and authentication sizes, output chunks), callback counters and error messages raise that to 86% (869 of 1,012; most of the rest change behaviour in no observable way, or only an error message's wording). See [Development](docs/16-development.md#mutation-testing).
- The wire protocol is documented frame by frame in [How PyHP works inside](docs/15-internals.md#the-wire-protocol-frame-by-frame): start-up, the handshake, every operation with its fields and result, error kinds, callback and output frames, value tags and the version history. A test fails when the worker gains an operation or value tag the reference doesn't describe.
- [Versions and compatibility](docs/17-compatibility.md): what the 1.0 promise covers (public API, options, CLI and exit codes, `extra.pyhp` keys, the Python `pyhp` module) and what it doesn't; deprecations warn with `E_USER_DEPRECATED` for at least one minor release before removal in a major; end-of-life PHP and Python versions can be dropped in a minor release; fixes go into the latest minor release.
- `tests/php/public-api.txt` and `PublicApiTest`: the public API is listed (classes, constants, properties, methods with parameter names, types and defaults), and the tests fail when it changes without the list being updated. See [Development](docs/16-development.md#the-public-api-list).

### Changed
- **Breaking (security):** the working directory is no longer on Python's `sys.path`, and neither is the worker's own folder. Before, a file in PHP's working directory named after a module (`random.py`, `json.py`, ...) would run on the next import, which is dangerous when that folder holds files other people can write. Only Python's own paths and the `paths` option are importable now. *Upgrade:* if you imported your own modules from the working directory, add it: `Py::config(['paths' => [getcwd()]])`, or better, the folder your modules are in.
- On Windows the worker's port is bound exclusively, so no other process can share it; a connection that doesn't authenticate is dropped after 2 seconds instead of 5.
- The security docs explain that names (functions, classes, modules, attributes, `execFile()` paths) are code and must never come from user input, and that Python errors shouldn't be shown to visitors. The full review is in `docs/tasks/v1-security-review.md`.
- **Breaking:** PyHP needs Python 3.11 or newer. Python 3.10 reached its end of life in October 2026. On 3.10 the worker now stops at start-up and PHP throws `WorkerStartException` naming the version needed, and `pyhp setup` refuses it. *Upgrade:* point PyHP at Python 3.11 or newer (`pyhp setup --python=3.13`, or the `python` option).
- CI tests the oldest and newest supported versions: the worker on Python 3.11 and 3.15; the PHP library on PHP 8.2 with Python 3.11 and PHP 8.5 with Python 3.14, on Linux, Windows and macOS. A PHP 8.6 job runs on Linux as an early warning and can't fail the build. The setup smoke tests use PHP 8.5.
- Marked as internal (`@internal`), outside the compatibility promise: every class in `PyHP\Setup` and `PyHP\Console` (use `vendor/bin/pyhp` instead), `Config::locatePython()` (use `Config::detectPython()`), `Value\Vectorized::$callback` and `StaleProxyException::restarted()`. `PyObject`'s constructor was meant to be internal already; its marker now actually applies.
- Python side: `PhpError.error_id` and `PhpCallable.callback_id` are now `_error_id` and `_callback_id`; they are protocol details. `pyhp.PhpError` (`php_class`, `php_message`) and `pyhp.PhpCallable` stay public.
- **Breaking:** `Py::shared()` needs a name: `Py::shared('reports', ...)`. Without one it gave a session called "default" that wasn't `Py::default()`. *Upgrade:* pass the name you want; `Py::shared('default')` behaves as before.
- **Breaking:** `Session::config()` is now `Session::configuration()`, matching `Py::configuration()` (`Py::config()` changes the configuration, it doesn't read it). *Upgrade:* rename the call.
- **Breaking:** the `restart` option is now `auto_restart` (`autoRestart` as a `Config` argument and property), so it isn't confused with `Session::restart()`. The old key throws `ConfigException` saying what it was renamed to. *Upgrade:* `['restart' => true]` becomes `['auto_restart' => true]`.
- **Breaking:** `vendor/bin/pyhp` exits with 2 on wrong usage (unknown command or option, missing or invalid option value) instead of 1, so scripts can tell a typo from a failure. Failures still exit with 1. *Upgrade:* only scripts that check for exactly 1 after a usage error are affected.
- Roadmap: 1.0.0 (a reviewed, frozen API with release candidates first) now comes before `pyhp/laravel`, so the Laravel package builds on a stable API.

### Fixed
- The `Py::rows()` speed test no longer fails under Xdebug: it's now separate from the correctness check, takes the best of three runs, and is skipped while Xdebug instruments PHP (which slows PHP code several times and made it measure Xdebug instead of PyHP).

## [0.4.0] - 2026-11-09

### Added
- Worker recovery: the `restart` option starts a fresh worker on the next call after a crash or timeout (the failed call still throws); `Session::onStart()` registers hooks that rebuild the session's state now and after every restart; `Session::restart()` resets a session on purpose; `Session::ping()` is a cheap health check.
- `Py::shared($name, $setup)`: one long-lived, self-healing session per name for queue workers and app servers, and `Py::closeShared()`.
- `StaleProxyException`: proxies from before a restart are refused, instead of reaching unrelated objects in the new worker; their releases are dropped so they can't free objects in it.
- Live output: what Python prints reaches PHP while a call runs, about a line at a time. `Session::onOutput()` sends it to a callback (progress bars, logs) instead of echoing or capturing it.
- `Py::vectorize()`: a PHP closure that Python calls once per batch of values (1,000 by default) instead of once per value, about 60 times faster than `apply()` on a Series; `elementwise: true` wraps a per-value closure. Used with `->pipe()`, a pandas Series comes back with its index and name.

### Changed
- Echo mode (the default) prints Python's output live while the call runs instead of all at once when it ends. Wire protocol version 3.

### Fixed
- `pyhp lock` and `pyhp setup` no longer try to delete temporary or half-written files that don't exist (it raised a PHP warning on Windows when a resolution failed).

## [0.3.0] - 2026-11-08

### Added
- Real-world tests of `pyhp setup` in CI (`tests/smoke/setup-smoke.php`): on Linux, Windows and macOS, with uv (downloading Python) and with the system Python, a fresh Composer project installs PyHP, runs `pyhp setup` twice and `pyhp info`, and checks that a session runs in the new venv.
- `TASKS.md`: the tasks of the current scope.
- The README points to the new starter project, `composer create-project pyhp/starter my-app` ([pyhp-php/starter](https://github.com/pyhp-php/starter)).
- Python requirements declared by installed Composer packages: any package can list them in its own `extra.pyhp.requirements` (a list or a requirements file), and `pyhp setup` collects them from `vendor/composer/installed.json` and installs them with the project's own. `setup` and `pyhp info` show which package asked for what.
- `extra.pyhp.ignore-packages` skips a Composer package's Python requirements; `extra.pyhp.overrides` forces versions over everyone else's rules (uv only; with pip, `setup` stops with an explanation).
- When the installer can't satisfy every rule, `pyhp setup` lists each Python package requested by more than one source and the Composer packages behind each rule.
- `config.vendor-dir` in `composer.json` is respected when looking for installed packages.
- `vendor/bin/pyhp lock`: resolves every Python requirement (the project's, Composer packages', overrides) and writes exact versions to `pyhp.lock`, for every operating system with uv (`uv pip compile --universal`) or for the current one with pip. `pyhp setup` installs exactly the locked versions and warns when the lock no longer matches the requirements; `pyhp info` reports whether it's up to date.
- `pyhp setup --exact`: the venv ends up containing exactly what the lock (or the requirements) lists; other packages are removed (`uv pip sync`, or pip install plus uninstall).
- The CI smoke test also covers a Composer package declaring its own Python requirement, `pyhp lock` and `pyhp setup --exact`, on all three systems.

### Changed
- Whole numbers that aren't Python `int` (`sympy.Integer`, anything registered as `numbers.Integral`) now arrive in PHP as `int` when they fit in 64 bits, instead of as proxies: `solve()` returns `[2, 3]` and `$py->functions->int()` isn't needed. Larger values, `int` subclasses (`IntEnum`), fractions and high-precision floats still stay proxies.

### Added
- `ROADMAP.md`: the plan for v0.3.0, v0.4.0 and the goals for v1.0.0.

### Changed
- Composer downloads of PyHP contain only what applications need (`src/`, `bin/`, `python/`, `composer.json`, README, CHANGELOG, LICENSE); tests, examples, docs, benchmarks and tool configs stay in the repository (`export-ignore` in `.gitattributes`).

### Removed
- `studies/` and `docs/PROJECT_CONTEXT.md`: design notes used while deciding features; the decisions are reflected in the docs, the CHANGELOG and `ROADMAP.md`.

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

[@dev]: https://github.com/pyhp-php/pyhp/compare/v0.4.0...develop
[0.4.0]: https://github.com/pyhp-php/pyhp/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/pyhp-php/pyhp/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/pyhp-php/pyhp/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pyhp-php/pyhp/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pyhp-php/pyhp/releases/tag/v0.1.0
