# 18. Upgrading

How to move a project to a newer PyHP. Every change is listed in the [changelog](../CHANGELOG.md); this page collects only what you may have to change in your own code. From 1.0 on, breaking changes only come in major versions, after a deprecation warning (see [Versions and compatibility](17-compatibility.md)).

## From 0.4 to 1.0

Update the requirement, then check the points below:

```bash
composer require pyhp/pyhp:^1.0
vendor/bin/pyhp setup
```

### Changes that need a code change

| What changed | What to do |
|---|---|
| PyHP needs **Python 3.11** or newer (3.10 reached its end of life). On 3.10 the worker stops at start-up with a `WorkerStartException` naming the version needed. | Point PyHP at Python 3.11+: `vendor/bin/pyhp setup --python=3.13`, or the `python` option. |
| The **working directory is no longer importable** from Python, and neither is the worker's own folder. A `random.py` or `json.py` lying in PHP's working directory used to run on the next import. | If you imported your own modules from the working directory, add their folder to `paths`: `Py::config(['paths' => [__DIR__.'/python']])`. |
| `Py::shared()` **needs a name**. | Pass the name you want: `Py::shared('reports', $setup)`. `Py::shared('default')` behaves like the old call without a name. |
| `Session::config()` is now **`Session::configuration()`**, like `Py::configuration()`. | Rename the call. |
| The `restart` option is now **`auto_restart`** (`autoRestart` in `new Config(...)`). The old key throws a `ConfigException` that names the new one. | `['restart' => true]` becomes `['auto_restart' => true]`. |
| `vendor/bin/pyhp` exits with **2 on wrong usage** (unknown command or option, missing or invalid value) instead of 1. Failures still exit with 1. | Only scripts that check for exactly `1` after a usage error need a change. |

### Now internal

These were public before; from 1.0 they're marked `@internal`, outside the compatibility promise, and can change in any release. Most projects never used them.

| Internal now | Use instead |
|---|---|
| Every class in `PyHP\Setup` and `PyHP\Console` | `vendor/bin/pyhp` (`setup`, `lock`, `info`, `info --json`) |
| `Config::locatePython()` | `Config::detectPython()` |
| `Value\Vectorized::$callback`, `StaleProxyException::restarted()` | Nothing: `Py::vectorize()` and catching `StaleProxyException` are unchanged |
| `PhpError.error_id`, `PhpCallable.callback_id` in Python (now `_error_id`, `_callback_id`) | `PhpError.php_class` and `PhpError.php_message` |

### New in 1.0, worth using

* `Py::toPhp()` copies NumPy arrays as raw bytes, and `Py::ndarray()` sends PHP arrays to NumPy the same way: 3–10× faster than `->tolist()` and JSON. See [NumPy arrays](08-pandas-and-numpy.md#numpy-arrays).
* `vendor/bin/pyhp info --json` reports the setup as JSON for scripts and CI. See [The pyhp command](03-command-line.md).
* A Docker base image with PHP, Python, uv and Composer: `ghcr.io/pyhp-php/pyhp`. See [Deployment](13-deployment-and-security.md#docker).

## Before 0.4

Versions 0.1 to 0.4 added features without breaking changes, apart from the package's new name in 0.1.1: `composer remove ahmedsalheia/pyhp && composer require pyhp/pyhp`. The namespace stayed `PyHP\`.

---

← [Versions and compatibility](17-compatibility.md) · [Contents](README.md)
