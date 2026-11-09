# 3. The pyhp command

PyHP installs a small command line tool as `vendor/bin/pyhp` (`vendor\bin\pyhp` on Windows). Run it from your project's root folder, where `composer.json` is.

| Command | What it does |
|---|---|
| `pyhp setup` | Creates the project's Python virtual environment and installs its packages. |
| `pyhp lock` | Resolves every Python requirement and writes the exact versions to `pyhp.lock`. |
| `pyhp info` | Shows PHP, Python, venv, package and worker details, and what to fix. |
| `pyhp help` | Lists the commands and options. |

## `vendor/bin/pyhp setup`

Run it from your project's root folder (where `composer.json` is). It's safe to run again: an existing `.venv` is reused and only the packages are brought up to date, so run it after changing `requirements`, after cloning the project, in CI and in your Dockerfile.

**With [uv](https://docs.astral.sh/uv/) installed** (recommended), setup uses it: `uv venv` creates the environment and **downloads the requested Python version if the machine doesn't have it**, and `uv pip install` installs packages many times faster than pip. Install uv once per machine:

```bat
winget install --id=astral-sh.uv -e                     :: Windows
curl -LsSf https://astral.sh/uv/install.sh | sh          # Linux / macOS
```

**Without uv**, setup uses the Python already installed: the `py` launcher on Windows (`py -3.12`), or `python3.12` / `python3` / `python` elsewhere, then `python -m venv` and `pip`. It needs Python 3.10 or newer; if the requested version isn't installed, it uses another 3.10+ version and says so.

Settings, all optional, under `extra.pyhp` in `composer.json`:

| Key | Default | Meaning |
|---|---|---|
| `python` | uv's or the system's choice | Python version for the environment, like `"3.12"`. |
| `requirements` | `requirements.txt` if it exists | A list of packages in pip syntax (`["pandas>=2", "spacy==3.8.*"]`), or the path of a requirements file (`"python/requirements.txt"`). |
| `venv` | `.venv` | Folder of the environment, relative to the project root or absolute. |
| `ignore-packages` | none | Composer packages whose Python requirements are skipped, like `["acme/heavy-nlp"]`. See [below](#python-requirements-from-composer-packages). |
| `overrides` | none | Version rules that replace everyone else's, like `["numpy<2"]`. **Needs uv.** |

Options:

| Option | Effect |
|---|---|
| `--python=3.13` | Overrides `extra.pyhp.python`. |
| `--venv=DIR` | Overrides `extra.pyhp.venv`. |
| `--no-uv` | Don't use uv even if it's installed. |
| `--exact` | Make the venv match `pyhp.lock` (or the requirements) exactly, removing every other package. See [Exact environments](#exact-environments-pyhp-lock-and---exact). |
| `--dry-run` | Only print the commands it would run. |

On Windows, Composer also creates `vendor\bin\pyhp.bat`, so `vendor\bin\pyhp setup` works in Command Prompt and PowerShell.

Setup installs packages; it doesn't remove ones you take out of the list. To start clean, delete the `.venv` folder and run setup again.

## Exact environments: `pyhp lock` and `--exact`

`pyhp setup` installs versions that satisfy your requirements, which can differ from machine to machine and from month to month. For the same versions everywhere, lock them, like `composer.lock` does for PHP packages:

```bat
vendor/bin/pyhp lock
```

This resolves every requirement (your project's, those of your Composer packages, and overrides) and writes **`pyhp.lock`** with the exact version of every Python package, including the packages they depend on. Commit it. From then on, `pyhp setup` installs exactly those versions, on your machine, your colleagues', in CI and in production.

* **With uv**, the lock is resolved for every operating system at once (`uv pip compile --universal`), so a lock written on Windows also works on Linux servers.
* **Without uv**, `pip install --dry-run --report` resolves it with the venv's Python (or a system Python). The lock is then valid only for that OS and Python version, and its header says so.
* `lock` accepts `--python`, `--no-uv` and `--dry-run`, like `setup`.
* The lock records a fingerprint of the requirements it came from. When you change them, `setup` warns that `pyhp.lock` is out of date (and still installs it), and `pyhp info` lists it as a problem. Run `pyhp lock` again.

`pyhp setup --exact` goes one step further: the venv ends up containing **exactly** what the lock lists, and packages installed by hand or left over from old requirements are removed (uv uses `uv pip sync`; without uv, PyHP installs the lock with pip and uninstalls everything else except pip, setuptools and wheel). Without a `pyhp.lock`, `--exact` resolves the requirements into a temporary lock first.

```bat
vendor/bin/pyhp setup --exact     # in CI and deploy scripts
```

A venv created by uv contains no pip, so to switch an existing project from uv to `--no-uv`, delete `.venv` first.

## Python requirements from Composer packages

Any Composer package can declare the Python packages it needs, exactly the way your project does, in its own `composer.json`:

```json
{
    "name": "acme/text-tools",
    "require": { "pyhp/pyhp": "^0.3" },
    "extra": { "pyhp": { "requirements": ["spacy>=3.8"] } }
}
```

`pyhp setup` reads every installed package's `extra.pyhp` from `vendor/composer/installed.json`, adds your project's own requirements, and installs everything into one environment. So installing a PHP package built on PyHP is `composer require acme/text-tools` followed by `vendor/bin/pyhp setup`. Nobody has to copy Python requirements around.

* A package can list requirements or name a requirements file inside the package (`"requirements": "python/requirements.txt"`). Package files can't use pip options such as `-r` or `-e`.
* `setup` prints which package asked for what, and so does `pyhp info`. Any installed package can make `setup` install Python packages, so always check that list, just as you would check what a Composer package runs.
* Only the root project's `python`, `venv`, `ignore-packages` and `overrides` settings count; packages can only add requirements.
* PyHP's own `extra.pyhp`, which lists its development tools, is never collected.

**Your project stays in charge:**

| You want to | Do this |
|---|---|
| Use a stricter version than a package asks for | Add your own requirement, e.g. `"pandas>=2.2"`. Every rule is passed to the installer, which picks a version that satisfies all of them. |
| Skip a package's Python requirements | `"ignore-packages": ["acme/heavy-nlp"]`. You then install what it needs yourself, or not at all. |
| Force a version even if a package forbids it | `"overrides": ["numpy<2"]` replaces every other rule for `numpy`. Needs uv (`uv pip install --override`); with pip, `setup` stops and says so. |

**When the rules can't all be met**, the installer fails. `setup` then lists each Python package that was requested by more than one source, with the Composer packages behind each rule:

```
These Python packages are requested by more than one source; check that their version rules can all be met:
  six: six<1.16 (your project), six>=1.16 (acme/text-tools)
To resolve it, tighten your own requirement, force a version with extra.pyhp.overrides (uv), or skip a package's requirements with extra.pyhp.ignore-packages.
```

## `vendor/bin/pyhp info`

When something doesn't work, run this first and include its output in bug reports:

```bat
vendor/bin/pyhp info
```

It checks everything between your PHP code and Python, in the order PyHP uses it:

| Section | What it shows |
|---|---|
| **PyHP** | The installed PyHP version. |
| **PHP** | Version, thread safety, 32/64-bit, the `php` binary, the OS, the loaded `php.ini`, and whether `proc_open()` is allowed (PyHP needs it to start Python). |
| **Project** | The project root, the Python version and packages it asks for in `extra.pyhp` (or `requirements.txt`), the requirements each installed Composer package adds, ignored packages, overrides, and whether `pyhp.lock` is up to date. |
| **Python** | The interpreter PyHP will use and **why** (the `PYHP_PYTHON` variable, the project's venv, or the default command), the venv folder, uv's version if installed, Python's version and real executable, each required package with its installed version or `missing`, and a live test: it starts a PyHP worker and times its first answer. |
| **Problems** | Everything that needs fixing, with the command that fixes it. |

Example:

```
PyHP v0.2.0

PHP
  Version        8.4.1 (cli, NTS, 64-bit)
  Binary         C:\php\php.exe
  OS             Windows 10.0 (AMD64)
  php.ini        C:\php\php.ini
  proc_open      available

Project
  Root           D:\projects\shop
  Python wanted  3.12
  Requirements   pandas>=2, scikit-learn

Python
  Interpreter    D:\projects\shop\.venv\Scripts\python.exe
  Chosen by      the project's virtual environment
  Project venv   D:\projects\shop\.venv
  uv             uv 0.9.5 at C:\Users\you\.local\bin\uv.exe
  Version        3.12.7 (CPython)
  Executable     D:\projects\shop\.venv\Scripts\python.exe
  Packages       pandas 3.1.1, scikit-learn missing
  Worker         started and answered in 85 ms

Problems:
  - Not installed: scikit-learn. Run: vendor/bin/pyhp setup
```

It exits with code 0 when everything looks fine and 1 when it found problems, so CI and deploy scripts can run it as a check.

`info` reports what PyHP picks **when your code doesn't choose**. If your application sets the `python` option (`Py::config(['python' => ...])`), that interpreter is used instead. `Config::locatePython()` returns the same answer as `info` from PHP code: the interpreter, the reason (`PythonSource`) and the venv folder.

## Exit codes

Every command exits with:

| Code | Meaning |
|---|---|
| `0` | Success (for `info`: everything looks fine). |
| `1` | The command failed, or `info` found problems. |
| `2` | Wrong usage: an unknown command or option, or a missing or invalid option value. Nothing was run. |

---

← [Installation](02-installation.md) · [Contents](README.md) · [Core concepts](04-core-concepts.md) →
