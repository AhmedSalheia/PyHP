# v1.0 public API review

Everything a PyHP user can touch, and what happens to it before the 1.0 freeze: **keep** (frozen as is), **rename / change** (a breaking cleanup now, with an upgrade note in the CHANGELOG), or **internal** (marked `@internal`, no compatibility promise). Reviewed on develop after 0.4.0 (2026-11-09).

Status column: ✅ decided · 🔧 a fix, no decision needed.

## 1. What 1.0 promises

Public API, covered by SemVer from 1.0:

- PHP classes in `PyHP\` and `PyHP\Value\` and `PyHP\Exception\`, minus anything marked `@internal`.
- The `Config` options (array keys and constructor arguments) and their defaults' meaning.
- The CLI: commands, options and exit codes of `vendor/bin/pyhp`.
- The `extra.pyhp` keys in `composer.json`.
- The Python-side module `pyhp` (`PhpError`, `PhpCallable`) and the session namespace behaviour (`__name__ == "__pyhp__"`).
- The behaviour documented in `docs/` (value conversion rules, timeouts, restart, output modes).

Not public: `PyHP\Internal\*`, `PyHP\Setup\*`, `PyHP\Console\*`, methods marked `@internal`, the wire protocol (versioned and documented, but PHP and Python halves always ship together), the exact text of error messages.

## 2. `Py` (static facade)

| API | Proposal | Status |
|---|---|---|
| `config()`, `configuration()`, `session()`, `default()`, `reset()` | keep | ✅ |
| `shared(string $name = 'default', ...)`, `closeShared()` | `Py::shared()` with no name gives a session called "default" that is **not** `Py::default()`: two different "default" sessions. Make `$name` required, so `Py::shared('reports')` is the only form. | ✅ Q1: name required |
| `exec`, `execFile`, `eval`, `import`, `vars`, `functions`, `modules`, `classes` | keep (shortcuts to `Py::default()`) | ✅ |
| `tuple`, `slice`, `dict`, `vectorize` | keep | ✅ |
| `len`, `repr`, `type`, `ref`, `toPhp`, `rows`, `columns` | keep | ✅ |

## 3. `Session`

| API | Proposal | Status |
|---|---|---|
| `new Session(Config)`, `exec`, `execFile`, `eval`, `import`, `close`, `isOpen` | keep | ✅ |
| `$vars`, `$functions`, `$modules`, `$classes` | keep | ✅ |
| `onStart()`, `onOutput()`, `restart()`, `ping()`, `info()`, `takeOutput()` | keep | ✅ |
| `config()` | Reads the config, while `Py::config()` *changes* it and `Py::configuration()` reads it. Rename to `configuration()` to match `Py`. | ✅ Q2: rename |
| `log()` | Reads like "write to the log". Rename to `workerLog()` (it is the worker's stderr). | ✅ Q2: keep `log()` |
| `request()`, `callbackId()`, `callback()`, `codec()`, `release()`, `generation()` | internal (already marked); PHP has no "friend" classes, so they stay public methods but are excluded from docs and the promise | ✅ |
| `isOpen()` after a crash with `restart` on | returns false although the next call would restart; document it (use `ping()` to check *and* heal) | 🔧 docs |

## 4. `Config` and options

| API | Proposal | Status |
|---|---|---|
| options `python`, `timeout`, `startup_timeout`, `cwd`, `env`, `inherit_env`, `paths`, `output`, `max_message_bytes`, `log_file`, `iter_batch_size` | keep | ✅ |
| option `restart` | Same word as `Session::restart()`, which does something else (restart now vs. restart automatically after a failure). Rename to `auto_restart` / `autoRestart`. | ✅ Q3: rename |
| `fromArray()`, `with()`, constants `DEFAULT_*`, `MIN_MESSAGE_BYTES` | keep | ✅ |
| `defaultPython()`, `detectPython()` | keep | ✅ |
| `locatePython()` | Returns `Setup\PythonLocation`, pulling the whole Setup namespace into the promise; only `pyhp info` needs it. Internal. | ✅ |

## 5. `PyObject`, `Vars`, `Functions`, `Classes`, `Modules`

| API | Proposal | Status |
|---|---|---|
| all magic methods, `ArrayAccess`, `IteratorAggregate`, `Stringable`, `__call()` as the escape hatch for clashing names | keep | ✅ |
| `PyObject::__construct` | meant to be internal, but its `@internal` docblock is above the `$generation` property, so it doesn't apply. Move it. | 🔧 |
| `Vars::names()`, `Vars::ref()`, `Classes::names()`, `Modules::names()` | keep | ✅ |
| accessor constructors | internal (already marked) | ✅ |

## 6. Values (`PyHP\Value`)

| API | Proposal | Status |
|---|---|---|
| `Tuple`, `Slice`, `Dict` | keep | ✅ |
| `Vectorized::$callback` | holds the *wrapped* chunk closure, not the user's: an implementation detail. Mark the property internal. | 🔧 |

## 7. Exceptions

| API | Proposal | Status |
|---|---|---|
| `PyHPException` and its 10 subclasses, `PythonException::$pythonType/$pythonMessage/$pythonTraceback` | keep | ✅ |
| `Session::restart()` inside a callback throws a bare `PyHPException` | fine; documented as such | ✅ |
| `LogicException` from cloning/serializing proxies and assigning `classes`/`modules`; `InvalidArgumentException` from `Py::vectorize()` | keep: programmer errors, not PyHP runtime failures | ✅ |

## 8. `PyHP\Setup` and `PyHP\Console`

All internal (`@internal` on every class). The public contract of this area is the CLI and `extra.pyhp`, not the PHP classes behind them, so `pyhp setup` can keep improving after 1.0. | ✅

## 9. CLI (`vendor/bin/pyhp`)

| API | Proposal | Status |
|---|---|---|
| `setup`, `lock`, `info`, `help`; `--python`, `--venv`, `--no-uv`, `--exact`, `--dry-run` | keep | ✅ |
| exit codes | today 0 = success, 1 = failure **and** wrong usage. Use 2 for wrong usage (unknown command/option), the usual convention, so scripts can tell them apart. | ✅ Q4: 2 for usage |

## 10. `composer.json` `extra.pyhp`

Keys `python`, `venv`, `requirements`, `ignore-packages`, `overrides`: keep. kebab-case like Composer's own keys; `Config` options stay snake_case like PHP config arrays. | ✅

## 11. Python side

| API | Proposal | Status |
|---|---|---|
| `from pyhp import PhpError`; `.php_class`, `.php_message` | keep | ✅ |
| `PhpError.error_id`, `PhpCallable.callback_id` | protocol details: rename to `_error_id` / `_callback_id` | 🔧 |
| `pyhp.PhpCallable` | keep, for `isinstance()` checks | ✅ |
| namespace module `__pyhp__` | keep (frozen: users see it in `Py::type()` and `__name__`) | ✅ |

## 12. Wire protocol

Already versioned (3) with a clear error on a mismatch at start-up. 1.0 work: document every frame in `docs/15-internals.md`; no API decision needed. | ✅

## Decisions

Decided by Ahmed on 2026-11-09:

1. **Q1:** `Py::shared()` requires a name; `Py::default()` stays the quick-script session.
2. **Q2:** `Session::config()` becomes `Session::configuration()`; `Session::log()` stays.
3. **Q3:** the `restart` option becomes `auto_restart` (`autoRestart` as a constructor argument).
4. **Q4:** the CLI exits with 2 on wrong usage, 1 on failure, 0 on success.

## Work list

Breaking (each with a CHANGELOG upgrade note):
- [x] `Py::shared()`: `$name` required.
- [x] `Session::config()` → `configuration()`.
- [x] Option `restart` → `auto_restart` / `autoRestart`.
- [x] CLI: exit code 2 for wrong usage.

Non-breaking:
- [ ] `@internal` on `Config::locatePython()`, `Vectorized::$callback`, every class in `PyHP\Setup` and `PyHP\Console`; fix the misplaced `@internal` on `PyHP\PyObject::__construct`.
- [ ] Python: `PhpError.error_id` → `_error_id`, `PhpCallable.callback_id` → `_callback_id`.
- [ ] Docs: what `isOpen()` means with `auto_restart` on.
- [ ] A test that lists the public API (public classes and methods without `@internal`) and fails when it changes without the list being updated, so nothing slips into or out of the promise by accident.
- [ ] Compatibility promise page (section 1 of this review, polished) in `docs/`.
