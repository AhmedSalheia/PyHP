# 2. Installation

## Requirements

| | Minimum | Notes |
|---|---|---|
| PHP | **8.2**, 64-bit | No extensions beyond the defaults (`json`, `pcre`, `proc_open` allowed). `proc_open` must not be in `disable_functions`. |
| Python | **3.10** | Anything that runs Python 3.10+: python.org install, `venv`, `conda`, `pyenv`. The worker uses the standard library only. |
| Your Python libraries | whatever you use | Install them into the interpreter you point PyHP at (`python -m pip install pandas`). |

## Installation

**1. Install the package:**

```bat
composer require pyhp/pyhp
```

To work on a local copy instead (for example to try changes to PyHP itself), add it as a path repository in your project's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../PyHP" }
    ],
    "require": {
        "pyhp/pyhp": "*@dev"
    }
}
```

**2. Declare the Python packages your project needs**, in your project's `composer.json`:

```json
{
    "extra": {
        "pyhp": {
            "python": "3.12",
            "requirements": ["pandas>=2", "numpy"]
        }
    }
}
```

**3. Build the Python environment:**

```bat
vendor/bin/pyhp setup
```

That creates a virtual environment in `.venv` and installs the packages into it. PyHP then uses it automatically: no configuration, no "activating" the venv.

Every option of `pyhp setup` is explained in [The pyhp command](03-command-line.md).

### Which Python PyHP uses

When you don't set the `python` option, PyHP picks the interpreter in this order:

1. The `PYHP_PYTHON` environment variable.
2. The project's virtual environment: `.venv` (or `extra.pyhp.venv`) in the root of the Composer project, whatever the current directory is, so it also works from `public/index.php`.
3. `python` on Windows, `python3` elsewhere, found through your `PATH`.

`Config::detectPython()` returns that choice. To use any other interpreter, set it explicitly:

```php
use PyHP\Py;

Py::config(['python' => 'C:/tools/python312/python.exe']);
```

### Without the setup command

You can also build the environment yourself; PyHP still finds a `.venv` in the project root:

```bat
py -3 -m venv .venv                                   :: Windows
.venv\Scripts\python.exe -m pip install pandas numpy
```

```bash
python3 -m venv .venv                                  # Linux / macOS
.venv/bin/python -m pip install pandas numpy
```

---

← [Introduction](01-introduction.md) · [Contents](README.md) · [The pyhp command](03-command-line.md) →
