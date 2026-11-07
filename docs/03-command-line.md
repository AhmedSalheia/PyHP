# 3. The pyhp command

PyHP installs a small command line tool as `vendor/bin/pyhp` (`vendor\bin\pyhp` on Windows). Run it from your project's root folder, where `composer.json` is.

| Command | What it does |
|---|---|
| `pyhp setup` | Creates the project's Python virtual environment and installs its packages. |
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

Options:

| Option | Effect |
|---|---|
| `--python=3.13` | Overrides `extra.pyhp.python`. |
| `--venv=DIR` | Overrides `extra.pyhp.venv`. |
| `--no-uv` | Don't use uv even if it's installed. |
| `--dry-run` | Only print the commands it would run. |

On Windows, Composer also creates `vendor\bin\pyhp.bat`, so `vendor\bin\pyhp setup` works in Command Prompt and PowerShell.

Setup installs packages; it doesn't remove ones you take out of the list. To start clean, delete the `.venv` folder and run setup again.

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
| **Project** | The project root, the Python version and packages it asks for in `extra.pyhp` (or `requirements.txt`). |
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

---

← [Installation](02-installation.md) · [Contents](README.md) · [Core concepts](04-core-concepts.md) →
