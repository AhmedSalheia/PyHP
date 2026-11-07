# 3. The pyhp command

PyHP installs a small command line tool as `vendor/bin/pyhp` (`vendor\bin\pyhp` on Windows). Run it from your project's root folder, where `composer.json` is.

| Command | What it does |
|---|---|
| `pyhp setup` | Creates the project's Python virtual environment and installs its packages. |
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

---

← [Installation](02-installation.md) · [Contents](README.md) · [Core concepts](04-core-concepts.md) →
