# 16. Development

## Development

```
PyHP/
├── src/                       PHP library (namespace PyHP\)
│   ├── Py.php                 static facade + helpers
│   ├── Session.php            one worker + namespace
│   ├── Vars.php, Functions.php
│   ├── PyObject.php           the proxy
│   ├── Config.php, OutputMode.php
│   ├── Setup/                 pyhp setup: SetupConfig, Installer, ProcessRunner
│   ├── Console/               the vendor/bin/pyhp command line
│   ├── Value/                 Tuple, Slice, Dict markers
│   ├── Exception/             exception hierarchy
│   └── Internal/              Worker (process + socket), Codec (wire format)
├── bin/pyhp                   command line tool (vendor/bin/pyhp)
├── python/pyhp_worker.py      the worker (standard library only)
├── requirements-dev.txt       Python packages for the examples, optional tests and dev tools
├── tests/php/                 Pest tests (need a real interpreter)
├── tests/python/              pytest tests for the worker
├── examples/                  runnable examples + sample data
├── benchmarks/                overhead.php, startup-trace.php
└── docs/                      the documentation you're reading
```

### PHP checks (Windows)

```bat
composer install
php bin/pyhp setup
vendor\bin\pint --test
vendor\bin\phpstan analyse
vendor\bin\pest --coverage --min=80
```

Or all at once: `composer check`. Coverage needs PCOV or Xdebug. The tests use the `.venv` that `php bin/pyhp setup` creates (or the interpreter in `PYHP_TEST_PYTHON`). Tests for optional libraries (pandas, NumPy, SymPy, spaCy, matplotlib, Pillow, scikit-learn) are skipped automatically when the library isn't installed.

### Python checks

`php bin/pyhp setup` installs the tools too. Then:

```bat
.venv\Scripts\python.exe -m ruff format --check python tests/python
.venv\Scripts\python.exe -m ruff check python tests/python
.venv\Scripts\python.exe -m mypy
.venv\Scripts\python.exe -m pytest --cov
```

### Git hooks

Activate the hooks once per clone:

```bat
git config core.hooksPath .githooks
```

* **`pre-commit`** formats, lints and type-checks what you commit: staged PHP files are formatted with Pint (and re-staged) and checked with PHPStan; staged Python files are formatted with `ruff format` (re-staged) and checked with `ruff check` and `mypy`. Commits without PHP or Python files skip all of it. It refuses files that are only partly staged, because re-staging them after formatting would also commit the parts you left out. Python tools come from `.venv` when it exists (`python -m pip install ruff mypy` there).
* **`pre-push`** blocks direct pushes to `main` and `develop`; use pull requests.

Tests aren't run on commit (the PHP suite takes about 40 s); `composer check`, pytest and CI run them.

### Continuous integration

`.github/workflows/ci.yml` runs every check on GitHub Actions for each push and pull request: the Python worker on Python 3.10 and 3.13, and the PHP suite on PHP 8.2 and 8.4 with pandas installed, on Linux, Windows and macOS. The Linux PHP jobs also install everything in `requirements-dev.txt`, so every optional library suite runs there; elsewhere those suites skip.

The `setup-smoke` jobs test `pyhp setup` for real on all three systems, once with uv (forced to download Python) and once with the system Python: `tests/smoke/setup-smoke.php` creates a Composer project that installs PyHP from the checkout plus a local package declaring its own Python requirement, runs `pyhp setup` twice and `pyhp info`, checks that a session runs in the new venv with both packages, writes `pyhp.lock`, and checks that `pyhp setup --exact` removes a stray package. Run it locally with `php tests/smoke/setup-smoke.php` (or `--no-uv`).

macOS runners are free for public repositories. For private ones they use your Actions minutes at a higher rate than Linux (check GitHub's current billing page).

### Rules the code follows

TDD, 80% minimum coverage, `declare(strict_types=1)` everywhere, PHPStan level max, `mypy --strict`, Ruff (`E,F,W,I,B,UP,N,S,ANN,SIM,RUF`), and fail-loudly exceptions. The worker stays standard-library-only.

### The public API list

`tests/php/public-api.txt` lists PyHP's public API: every public class, constant, property and method in `src/` that isn't marked `@internal`, with parameter names (named arguments make them part of the API), types and defaults. `tests/php/PublicApiTest.php` fails whenever the code and the list differ, so nothing enters or leaves the public API by accident.

* Adding to the public API: update the list and add the change to `CHANGELOG.md`.
* Changing or removing something in it is a **breaking change**: after 1.0 it needs a deprecation first, and always an upgrade note in the CHANGELOG.
* Code that users shouldn't call gets `@internal` in its docblock. Everything in `PyHP\Internal`, `PyHP\Setup` and `PyHP\Console` is internal.

To update the list after an intended change:

```bash
PYHP_UPDATE_API=1 vendor/bin/pest tests/php/PublicApiTest.php
```

On Windows PowerShell: `$env:PYHP_UPDATE_API = '1'; vendor/bin/pest tests/php/PublicApiTest.php; Remove-Item Env:PYHP_UPDATE_API`.

---

← [How PyHP works inside](15-internals.md) · [Contents](README.md) · [Versions and compatibility](17-compatibility.md) →
