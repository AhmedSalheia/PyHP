# PyHP

**Use Python libraries — pandas, NumPy, anything — from PHP, in PHP syntax, without a compiled extension.**

```php
$py = Py::session();
$pd = $py->import('pandas');

$sales = $pd->read_csv('sales.csv');
$py->vars->minRevenue = 5000;                              // share a PHP value with Python

$big    = $sales[$sales['revenue']->ge($py->vars->minRevenue)];
$totals = $big->groupby('region')['revenue']->sum()->sort_values(ascending: false);

print_r($totals->to_dict());                                // ['west' => 306861.5, ...]
```

> **Status: MVP (0.x, unreleased).** Tested on Linux and Windows with real pandas and NumPy (see [Platform support](#platform-support)). APIs may still change before the first release.

---

## Contents

1. [What PyHP is — and what it is not](#what-pyhp-is--and-what-it-is-not)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Quick start](#quick-start)
5. [Core ideas: sessions, copies and proxies](#core-ideas-sessions-copies-and-proxies)
6. [API reference](#api-reference)
7. [How values are converted](#how-values-are-converted)
8. [pandas and NumPy in practice](#pandas-and-numpy-in-practice)
9. [Printed output and the log](#printed-output-and-the-log)
10. [Errors](#errors)
11. [Object lifetime and memory](#object-lifetime-and-memory)
12. [Performance](#performance)
13. [Where you can use it (CLI, web, long-running PHP)](#where-you-can-use-it)
14. [Security](#security)
15. [Platform support](#platform-support)
16. [Imports](#imports)
17. [Every limitation in one list](#every-limitation-in-one-list)
18. [How it works inside](#how-it-works-inside)
19. [PyHP vs. swoole/phpy](#pyhp-vs-swoolephpy)
20. [Development](#development)
21. [Roadmap](#roadmap)

---

## What PyHP is — and what it is not

PyHP starts **one separate Python process per session** (the *worker*) and talks to it over a private local socket. Your Python objects live in that process. PHP gets lightweight **proxies** that forward every attribute read, method call, index and loop to the real object.

That design makes some things easy and some things impossible, and it's worth knowing which before you start:

| PyHP is | PyHP is not |
|---|---|
| A pure Composer package: no PHP extension, no compiling | An embedded interpreter. Python does **not** run inside the PHP process |
| Any Python 3.10+ you choose: system, venv, conda | A Python installer. It never installs Python or pip packages |
| Crash-isolated: if Python dies, PHP gets an exception and keeps running | Fast at chatty, fine-grained calls (each call is a round trip, ~0.1 ms) |
| Stateful: imports and variables survive between calls in a session | Shared between web requests (one PHP-FPM request = its own session) |
| For trusted code you wrote | A sandbox. Never run user-submitted Python with it |

**The rule of thumb:** work on whole objects (a DataFrame, an array) with a few calls, and let Python do the heavy lifting. Don't loop over a million rows from PHP.

---

## Requirements

| | Minimum | Notes |
|---|---|---|
| PHP | **8.2**, 64-bit | No extensions beyond the defaults (`json`, `pcre`, `proc_open` allowed). `proc_open` must not be in `disable_functions`. |
| Python | **3.10** | Anything that runs Python 3.10+: python.org install, `venv`, `conda`, `pyenv`. The worker uses the standard library only. |
| Your Python libraries | whatever you use | Install them into the interpreter you point PyHP at (`python -m pip install pandas`). |

---

## Installation

PyHP isn't on Packagist yet. Until then, install it from a local folder.

**1. Put the PyHP folder next to your project** (or anywhere), then in your project's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../PyHP" }
    ],
    "require": {
        "ahmedsalheia/pyhp": "*@dev"
    }
}
```

```bat
composer update ahmedsalheia/pyhp
```

**2. Create a virtual environment for your Python libraries** (recommended, so your project's Python packages are isolated):

Windows (Command Prompt / PowerShell):

```bat
py -3 -m venv .venv
.venv\Scripts\python.exe -m pip install pandas numpy
```

Linux / macOS:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install pandas numpy
```

**3. Tell PyHP which interpreter to use:**

```php
use PyHP\Py;

Py::config(['python' => __DIR__ . '/.venv/Scripts/python.exe']);   // Windows
Py::config(['python' => __DIR__ . '/.venv/bin/python']);           // Linux/macOS
```

You don't need to "activate" the venv. Pointing at its interpreter is enough.

If you don't configure anything, PyHP runs `python` on Windows and `python3` elsewhere, found through your `PATH`.

---

## Quick start

```php
<?php
require 'vendor/autoload.php';

use PyHP\Py;

$py = Py::session();                         // starts a Python worker

$py->exec('import math');                    // run statements
echo $py->eval('math.sqrt(2)');              // evaluate an expression → 1.4142135623731

$py->vars->name = 'Ahmed';                   // PHP → Python variable
$py->exec('greeting = f"Marhaba, {name}!"');
echo $py->vars->greeting;                    // Python → PHP: "Marhaba, Ahmed!"

$py->exec('def add(a, b=0): return a + b');
echo $py->functions->add(2, b: 3);           // call a Python function: 5

$py->close();                                // stop the worker
```

More complete, runnable examples are in [`examples/`](examples):

| File | Shows |
|---|---|
| `01_basics.php` | exec, eval, printed output, catching Python errors |
| `02_vars_and_functions.php` | `execFile`, sharing variables, keyword arguments, `classes`, Python objects as proxies |
| `03_pandas_report.php` | a full pandas report written in PHP syntax |
| `04_numpy.php` | NumPy arrays, tuple indexes, slices, scalars |

Run them with your interpreter:

```bat
set PYHP_PYTHON=C:\path\to\.venv\Scripts\python.exe
php examples\03_pandas_report.php
```

---

## Core ideas: sessions, copies and proxies

### Session

A `Session` is one Python process plus one Python **global namespace**. Everything you do through the same session shares state: a variable set with `vars`, a function defined with `exec()`, a module imported with `import()`. A new session starts empty.

```php
$a = Py::session();
$b = Py::session();
$a->vars->x = 1;
isset($b->vars->x);   // false — different processes
```

### Copies vs. proxies

Every time Python hands a value back to PHP, PyHP decides between two things:

* **Plain data is copied** into a PHP value: `None`, `bool`, `int`, `float`, `str`, `bytes`, `list`, `tuple`, `dict`, and NumPy scalars. After that, the PHP value and the Python value are independent.
* **Everything else stays in Python**, and PHP gets a `PyObject` proxy: DataFrames, arrays, modules, functions, class instances, sets, dates, …

```php
$py->vars->items = [1, 2];
$copy  = $py->vars->items;        // PHP array [1, 2]: a copy
$proxy = $py->vars->ref('items'); // PyObject: the live Python list
$proxy->append(3);                // changes Python's list
$py->vars->items;                 // [1, 2, 3]
```

The exact rules are in [How values are converted](#how-values-are-converted).

### Proxies

A `PyObject` holds an ID, not data. Each operation on it is sent to Python:

```php
$df->shape;            // getattr(df, "shape")
$df->head(3);          // df.head(3)
$df['amount'];         // df["amount"]
foreach ($df as $col)  // for col in df
(string) $df           // str(df)
```

When the last PHP reference to a proxy disappears, the Python object is released, unless Python itself still references it.

---

## API reference

### `Py` (static facade)

| Method | What it does |
|---|---|
| `Py::config(array $options)` | Change the configuration used by sessions created **from now on**. Options are merged into the current configuration. |
| `Py::configuration(): Config` | The current configuration. |
| `Py::session(array $overrides = []): Session` | A new independent session. `$overrides` apply to this session only. |
| `Py::default(): Session` | A shared default session, started on first use and restarted automatically if it was closed. |
| `Py::reset(bool $forgetConfig = false)` | Close the default session. With `forgetConfig: true` also go back to default options. |
| `Py::exec()`, `Py::execFile()`, `Py::eval()`, `Py::import()`, `Py::vars()`, `Py::functions()`, `Py::modules()`, `Py::classes()` | Shortcuts to the same methods on `Py::default()`. |
| `Py::tuple(...$items)` | A value sent as a Python `tuple`. |
| `Py::slice($start, $stop = null, $step = null)` | A value sent as a Python `slice`: `Py::slice(1, 3)` is `1:3`, `Py::slice(null, null, -1)` is `::-1`. |
| `Py::dict(array $items)` | A value sent as a Python `dict`, even if the array is empty or list-shaped. |
| `Py::len(PyObject $o): int` | Python `len(o)`. |
| `Py::repr(PyObject $o): string` | Python `repr(o)`. |
| `Py::type(PyObject $o): string` | The Python type, e.g. `pandas.DataFrame`, `int`, `__pyhp__.Invoice`. |
| `Py::toPhp(PyObject $o): mixed` | Copy the object into PHP, also accepting sets and subclasses of dict/list/str/int/float. Throws instead of returning nested proxies. |
| `Py::rows(PyObject $df): array` | A pandas DataFrame as a list of rows, fast and `json_encode()`-ready; see [Copying tables into PHP](#copying-tables-into-php). |
| `Py::columns(PyObject $df): array` | The same as one list per column: the most compact copy. |

> `Py::config()` does **not** change a default session that is already running. Call `Py::reset()` after changing options if you want the default session to pick them up.

### Configuration options

Use these keys with `Py::config()`, `Py::session()` and `Config::fromArray()`. Unknown keys and wrong types throw `ConfigException` immediately, so a typo doesn't fail silently.

| Option | Type | Default | Meaning |
|---|---|---|---|
| `python` | string | `python` (Windows), `python3` (others) | The interpreter: a command on `PATH` or a full path. Point it at a venv's interpreter to use that venv. |
| `timeout` | float\|null | `30` | Seconds **one call** may take. `null` = wait forever. On timeout the worker is killed (see [Errors](#errors)). |
| `startup_timeout` | float | `10` | Seconds to wait for the worker to start and connect. |
| `cwd` | string\|null | `null` (PHP's) | Working directory of the Python process. Relative paths *inside Python* resolve against it. |
| `env` | array<string,string> | `[]` | Extra environment variables for Python. |
| `inherit_env` | bool | `true` | Start from PHP's environment (`true`) or from only `env` (`false`). On Windows `SYSTEMROOT` is always passed, because Python needs it. |
| `paths` | list<string> | `[]` | Extra folders added to Python's `sys.path`, so your own modules can be imported. |
| `output` | `OutputMode` or `'echo'`/`'capture'` | `Echo` | What happens to text Python prints. See [Printed output](#printed-output-and-the-log). |
| `max_message_bytes` | int | 64 MiB | Largest request or result allowed. Protects PHP from accidentally copying huge data. Minimum 1024. |
| `log_file` | string\|null | `null` (temp file) | Where the worker's stderr goes. A temp file is deleted when the session closes; set a path to keep it. |
| `iter_batch_size` | int | `100` | Items fetched per round trip in `foreach`. |

Precedence: built-in defaults → `Py::config()` → `Py::session([...])` overrides.

You can also build a `Config` directly with named arguments: `new Session(new Config(python: '...', timeout: 5))`.

### `Session`

| Method | What it does |
|---|---|
| `exec(string $code): void` | Run Python statements in the session namespace. |
| `execFile(string $path): void` | Run a `.py` file **in the session namespace**: what it defines becomes available through `vars` and `functions`. Relative paths are resolved from PHP's current directory. |
| `eval(string $expression): mixed` | Evaluate one expression and return its value. |
| `import(string $module, ?string $as = null, ?string $from = null): mixed` | Import like Python and bind the name in the session; see [Imports](#imports). |
| `$modules` | Modules bound in the session (see below). |
| `$classes` | Python classes, to create instances (see below). |
| `$vars` | Global variables (see below). |
| `$functions` | Global callables (see below). |
| `takeOutput(): string` | In capture mode, the text printed since the last call to `takeOutput()`; then clears it. |
| `log(): string` | Everything the worker wrote to stderr so far (warnings, crash output). |
| `info(): array` | `protocol`, `python` (version), `executable`, `objects` (live proxies), `pid`. |
| `isOpen(): bool` | Whether the worker is running. |
| `close(): void` | Stop the worker. Safe to call twice. Also happens automatically when the session object is destroyed. |
| `config(): Config` | The session's configuration. |

A few `Session` methods (`request()`, `codec()`, `release()`) are public for PyHP's internal use and are marked `@internal`. Don't call them; they may change without notice.

#### `exec` vs `eval` vs `execFile`

* `exec('x = 1')` runs **statements** and returns nothing. `exec('1 + 1')` computes and discards the result.
* `eval('x + 1')` evaluates **one expression** and returns it. `eval('x = 1')` is a `SyntaxError`.
* `print()` inside either produces *output*, not a return value: `eval('print(42)')` returns `null` and prints `42`.
* `execFile($path)` is like `exec(file_get_contents($path))`, but tracebacks show the real file name and line numbers, the file's `# -*- coding -*-` declaration is honoured, and `__file__` is set while it runs.

> **`execFile` differs from `python script.py` in two ways:**
> * `__name__` is `"__pyhp__"`, not `"__main__"`, so `if __name__ == "__main__":` blocks **do not run**. Put the code you want to run at the top level, or call the function you need via `functions`.
> * The script's own folder is **not** added to `sys.path`. If it imports sibling modules, add the folder with the `paths` option.

### `vars`: Python global variables

```php
$py->vars->limit = 10;          // limit = 10
$py->vars->limit;               // 10 (copied, or a proxy for non-plain values)
isset($py->vars->limit);        // true if the global exists
unset($py->vars->limit);        // del limit (no error if it doesn't exist)
$py->vars->names();             // ['limit', ...] — your globals, without __dunder__ names
$py->vars->ref('items');        // always a live proxy, never a copy
$py->vars->{'odd name'};        // any string works as a name
```

* Reading a name that doesn't exist throws `PythonException` (`NameError`).
* Reading falls back to Python builtins, like Python does: `$py->vars->len` is the builtin `len` (as a proxy). `isset()` and `names()` only see your own globals.
* `names()` and `ref()` are PHP methods. A Python variable called `names` or `ref` is still reachable as a **property**: `$py->vars->names` (no parentheses).
* **Modifying a copied value in place does nothing in Python**, and PHP warns you: `$py->vars->items[] = 3` gives *"Indirect modification of overloaded property … has no effect"*. Either assign the whole value back (`$py->vars->items = [...]`) or use a proxy: `$py->vars->ref('items')->append(3)`.

### `functions`: Python global callables

```php
$py->functions->greet('Ahmed');                  // greet('Ahmed')
$py->functions->greet('Ahmed', excited: true);   // greet('Ahmed', excited=True)
$py->functions->Invoice('Ahmed');                // classes are callables too (prefer $py->classes)
$py->functions->len([1, 2, 3]);                  // builtins work: 3
$fn = $py->functions->greet;                     // a callable proxy for later: $fn('Sam')
isset($py->functions->greet);                    // exists and is callable?
```

* PHP **named arguments become Python keyword arguments**. Positional arguments must come before named ones, which PHP enforces anyway.
* You can also spread an array: `$py->functions->f(...['a' => 1, 'b' => 2])`. String keys become keyword arguments, so keys don't even need to be valid PHP identifiers.
* `functions` has no ordinary PHP methods, so every Python name is reachable, whatever it's called.
* `functions` accepts anything callable, classes included. Use `classes` when you mean "create an instance": it refuses non-classes, which catches mistakes.

### `classes`: Python classes

```php
$py->exec('class Invoice: ...');                 // or import one: $py->import('Decimal', from: 'decimal')
$invoice = $py->classes->Invoice('Ahmed', currency: 'ILS');   // Invoice('Ahmed', currency='ILS') → proxy
$py->classes->dict(a: 1);                        // builtin classes work: ['a' => 1]
$cls = $py->classes->Invoice;                    // the class itself, as a proxy: $cls('Sam'), $cls->__name__
isset($py->classes->Invoice);                    // is "Invoice" a class?
$py->classes->names();                           // ['Invoice', ...] — your own and imported classes, not builtins
```

* Calling or fetching a name that isn't a class throws `PythonException` (`TypeError: 'make' is a function, not a class`), or `NameError` if the name doesn't exist.
* Static methods and class attributes go through the class proxy: `$py->classes->Invoice->from_dict([...])`.
* `$py->classes->X = ...` throws `LogicException`: define classes with `exec()`/`execFile()` or import them.
* `names()` is a PHP method. A Python class called `names` is still reachable as `$py->classes->names` (no parentheses) or `$py->classes->__call('names', [...])`.

### `modules`: imported modules

```php
$py->import('pandas', as: 'pd');
$py->exec('import json');
$py->modules->names();                           // ['pd', 'json'] — every name bound to a module
$py->modules->pd->read_csv('sales.csv');         // the module bound as "pd"
isset($py->modules->json);                       // is "json" bound to a module?
```

* The container shows the session namespace as it is: modules added with `import()` **and** modules imported inside `exec()`/`execFile()` code, under the names they're bound to (aliases included).
* Reading a name that isn't bound to a module throws `PythonException` (`TypeError` or `NameError`). Nothing is imported implicitly: use `$py->import()` to add modules.
* `$py->modules->x = ...` throws `LogicException`.

### `PyObject`: the proxy

| PHP | Python |
|---|---|
| `$o->name` | `o.name` |
| `$o->name = $v` | `o.name = v` |
| `isset($o->name)` | `hasattr(o, "name")` |
| `unset($o->name)` | `del o.name` |
| `$o->method(1, k: 2)` | `o.method(1, k=2)` |
| `$o(1, k: 2)` | `o(1, k=2)` |
| `$o[$key]` | `o[key]` (the key may be a proxy, `Py::tuple()`, `Py::slice()`) |
| `$o[$key] = $v` | `o[key] = v` |
| `$o[] = $v` | `o.append(v)` |
| `isset($o[$key])` | key exists **and** the value is not `None` (PHP `isset` semantics) |
| `unset($o[$key])` | `del o[key]` |
| `foreach ($o as $i => $item)` | `for item in o` (fetched in batches; `$i` is 0, 1, 2, …) |
| `(string) $o`, `echo $o` | `str(o)` |
| `Py::len($o)` | `len(o)` |
| `Py::repr($o)` | `repr(o)` |
| `Py::type($o)` | the type's qualified name |
| `Py::toPhp($o)` | strict deep copy into PHP |
| `var_dump($o)` | shows type, id and whether the session is open, **without** calling Python |

Things that look like they should work but behave differently:

* **`count($o)` throws `TypeError`.** `PyObject` deliberately doesn't implement PHP's `Countable`, because its `count()` method would hide Python methods called `count()`, and pandas has one (`DataFrame.count()`). Use `Py::len($o)`.
* **`if ($o)` is always true**: PHP objects are always truthy, even an empty DataFrame or an empty set. Ask Python instead: `$df->empty`, `Py::len($o) > 0`.
* **`$a == $b` and `$a === $b` compare proxies, not Python objects.** Two proxies to the same Python object (fetched twice) are not equal in PHP. Use `$py->functions->id($a) === $py->functions->id($b)` for identity, or call `$a->__eq__($b)` for Python equality.
* **`json_encode($o)` gives `{}`.** Convert first: `json_encode($o->to_dict())` or `json_encode(Py::toPhp($o))`.
* **Python operators have no PHP syntax.** `$a + $b`, `$a > 3`, `$a & $b` don't work on proxies. Use method forms: pandas and NumPy provide `add`, `sub`, `mul`, `truediv`, `gt`, `ge`, `lt`, `le`, `eq`, `ne`; for anything else use the dunder method directly (`$a->__and__($b)`, `$a->__or__($b)`, `$a->__invert__()`), or write the expression in Python with `eval()`.
* **Iterating a dict yields keys**, as in Python. For pairs: `foreach ($d->items() as [$k, $v])`.
* **Iterators and generators are consumed once.** A second `foreach` over the same generator yields nothing, exactly like Python.
* **Reserved names:** the proxy's own PHP methods hide Python methods with the same names: `offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`, `getIterator`, plus PHP magic methods (`__get`, `__set`, `__call`, `__invoke`, `__toString`, `__debugInfo`, `__clone`, `__serialize`, `__unserialize`, `__destruct`, `__construct`, `__isset`, `__unset`). To reach a Python method with one of these names, call `__call` directly: `$o->__call('getIterator', [])`.
* **Proxies can't be cloned or serialized** (`clone`, `serialize()`, storing in a PHP session or cache). Both throw `LogicException`. To copy the Python object, use Python: `$py->import('copy')->deepcopy($o)`.
* **A proxy only works in the session that created it.** Passing it to another session throws `ConversionException`.

---

## How values are converted

### PHP → Python (arguments, `vars` assignments, keys)

| PHP value | Python value | Notes |
|---|---|---|
| `null` | `None` | |
| `true` / `false` | `True` / `False` | |
| `int` | `int` | |
| `float` | `float` | `NAN`, `INF`, `-INF` are preserved. `2.0` stays a float. |
| `string`, valid UTF-8 | `str` | |
| `string`, not valid UTF-8 | `bytes` | Binary data stays binary. |
| list-shaped array (`[0 => a, 1 => b, ...]`) | `list` | Determined by `array_is_list()`. |
| any other array | `dict` | Integer keys stay `int`, string keys stay `str`. |
| `[]` (empty array) | `list` | Use `Py::dict([])` for an empty dict. |
| `PyObject` from the same session | the original object | No copy. |
| `Py::tuple(...)` | `tuple` | |
| `Py::slice(...)` | `slice` | |
| `Py::dict([...])` | `dict` | Even when list-shaped. |
| `Closure` / callable | ✗ `ConversionException` | Callbacks from Python into PHP aren't supported yet. |
| any other object (`DateTime`, enums, your classes) | ✗ `ConversionException` | Convert first: `$date->format('c')`, `$enum->value`, `(array) $dto`. |
| resource | ✗ `ConversionException` | |
| arrays nested deeper than 256 levels | ✗ `ConversionException` | |

### Python → PHP (results)

| Python value | PHP value | Notes |
|---|---|---|
| `None` | `null` | |
| `bool`, `numpy.bool_` | `bool` | |
| `int` within 64-bit range, NumPy integers | `int` | |
| `int` outside 64-bit range | **proxy** | e.g. `2**70` — no silent precision loss. |
| `float`, NumPy floats | `float` | `nan`/`inf` become `NAN`/`INF`. |
| `str` | `string` (UTF-8) | A `str` that can't be encoded as UTF-8 (lone surrogates) stays a proxy. |
| `bytes` | `string` (binary) | Becomes a plain PHP string: **it returns to Python as `str` if it happens to be valid UTF-8.** |
| `list`, `tuple` | list array | **Tuples come back as lists.** Send `Py::tuple()` if you need a tuple again. |
| `dict` with `str`/`int` keys | array | Order is preserved. |
| `dict` with other keys (tuples, `None`, `bool`, floats, …) | **proxy** | |
| `dict` whose keys would collide in PHP (`{1: …, "1": …}`) | **proxy** | PHP turns the key `"1"` into `1`. |
| everything else (sets, `datetime`, `Decimal`, DataFrames, arrays, modules, functions, instances, `pd.NA`, `pd.NaT`, NumPy `datetime64`) | **proxy** | |
| subclasses of `dict`/`list`/`int`/`str`/… (`OrderedDict`, `defaultdict`, `IntEnum`) | **proxy** | Only the exact built-in types are copied automatically. `Py::toPhp()` accepts subclasses. |

Inside containers, conversion happens **per item**. A dict holding a date comes back as a PHP array whose `'when'` entry is a proxy:

```php
$row = $py->eval("{'n': 1, 'when': __import__('datetime').date(2024, 1, 2)}");
$row['n'];                    // 1
$row['when']->isoformat();    // "2024-01-02"
```

### Round-trip surprises

* **PHP's own key rules apply.** A Python dict `{'1': 'a'}` arrives as `[1 => 'a']` (PHP converts numeric-string keys), and goes back to Python as `{1: 'a'}`.
* **A dict with keys `0, 1, 2, …` becomes a PHP list**, and therefore comes back to Python as a `list`.
* **Empty containers lose their type:** `{}`, `[]` and `()` all become `[]` in PHP and return to Python as `[]`.
* **Floats are exact.** Both sides use shortest round-trip representations, so `0.1 + 0.2` arrives as `0.30000000000000004`, the same double Python has.
* **Copying is recursive and complete.** Reading a list of a million items copies a million items. Keep big data in Python and copy small results; [`max_message_bytes`](#configuration-options) stops runaway copies.
* **Cycles are cut:** a list that contains itself comes back with a proxy where the cycle closes.

### `Py::toPhp()` (strict conversion)

`Py::toPhp($proxy)` copies the whole object or fails. It accepts everything automatic conversion accepts, plus `set`/`frozenset` (as lists) and subclasses of built-in types. It throws `ConversionException` instead of producing nested proxies, and also on cycles or nesting deeper than 256 levels. Use it when you need to be sure you have plain PHP data, for example before `json_encode()`.

---

## pandas and NumPy in practice

### The pattern

1. **Load and transform inside Python**, through proxies or `exec()`.
2. **Copy only the result** to PHP: `->to_dict()`, `->tolist()`, `->item()`, a scalar like `->sum()`, or a whole table with [`Py::rows($df)`](#copying-tables-into-php).
3. **Or don't copy at all**: write a file from Python (`->to_csv()`, `->to_parquet()`) and pass the path around.

```php
$pd = $py->import('pandas');
$df = $pd->read_csv('orders.csv', parse_dates: ['date']);

$df['revenue'] = $df['units']->mul($df['unit_price']);            // new column
$recent = $df[$df['date']->ge('2026-03-01')];                     // boolean mask
$summary = $recent->groupby('region')->agg(
    revenue: Py::tuple('revenue', 'sum'),                          // named aggregation
    orders: Py::tuple('revenue', 'count'),
);
$rows = $summary->reset_index()->to_dict('records');               // small → PHP array
```

### Translating Python syntax

| Python | PyHP |
|---|---|
| `df["a"]` | `$df['a']` |
| `df[["a", "b"]]` | `$df[['a', 'b']]` |
| `df[df.a > 5]` | `$df[$df['a']->gt(5)]` |
| `df[(df.a > 5) & (df.b < 3)]` | `$df[$df['a']->gt(5)->__and__($df['b']->lt(3))]` or `$df->query('a > 5 and b < 3')` |
| `~mask` | `$mask->__invert__()` |
| `df.a * 2` | `$df['a']->mul(2)` |
| `df.loc[mask, "b"] = 0` | `$df->loc[Py::tuple($mask, 'b')] = 0` |
| `df.iloc[0:3]` | `$df->iloc[Py::slice(0, 3)]` |
| `arr[1, 0]` | `$arr[Py::tuple(1, 0)]` |
| `arr[::2]` | `$arr[Py::slice(null, null, 2)]` |
| `df.sort_values("a", ascending=False)` | `$df->sort_values('a', ascending: false)` |
| `df.a.dt.year` | `$df['a']->dt->year` |
| `np.linalg.det(m)` | `$np->linalg->det($m)` |
| `len(df)` | `Py::len($df)` |
| `print(df)` | `echo $df;` |
| `lambda x: x * 2` | not possible from PHP. Define it in Python: `$py->exec('double = lambda x: x * 2')`, then pass `$py->functions->double` |

When a chain gets long or needs lambdas, write that part in Python. Both styles share the same objects:

```php
$py->vars->orders = $df;                        // hand a proxy to Python
$result = $py->eval("orders.assign(net=lambda d: d.revenue * 0.84).nlargest(5, 'net')");
```

### Copying tables into PHP

`Py::rows()` and `Py::columns()` are the fast, PHP-friendly way to copy a whole DataFrame:

```php
$rows = Py::rows($df);        // [['id' => 1, 'price' => 9.5, 'when' => '2024-01-02T03:04:05'], ...]
$cols = Py::columns($df);     // ['id' => [1, 2, ...], 'price' => [9.5, null, ...], ...]
echo json_encode($rows);      // works: no NAN, no proxies
```

They differ from `to_dict()` on purpose, to give you data PHP can use directly:

| In the DataFrame | `to_dict()` gives | `Py::rows()` / `Py::columns()` give |
|---|---|---|
| `NaN`, `None`, `pd.NA`, `NaT` | `NAN` / `null` / proxies | `null` |
| datetime (`datetime64`, with or without time zone) | `Timestamp` proxies | ISO 8601 string, e.g. `2024-01-02T03:04:05+02:00` |
| timedelta | `Timedelta` proxies | ISO 8601 duration, e.g. `P0DT0H1M30S` |
| categorical | values | values |
| `±inf` | `INF` / `-INF` | `INF` / `-INF` (still not valid JSON) |
| the index | included with `orient='index'` etc. | **left out**; call `->reset_index()` first to keep it |
| `Decimal`, `date`, other objects | proxies, one per cell | `ConversionException` naming the column; convert it first, e.g. `$df['amount'] = $df['amount']->astype('float')` |

* Column names must be strings or integers that stay distinct as PHP array keys (`'1'` and `1` collide in PHP); otherwise you get a `ConversionException`. Rename first, e.g. flatten a MultiIndex.
* A `Series` isn't accepted: use `$series->tolist()` or `Py::rows($series->to_frame())`.
* Floats are exact (no rounding), unlike pandas' own `to_json()`.

### Missing values and dates

* `numpy.nan` and float NaN → PHP `NAN`. Test with `is_nan()`, not `=== NAN`.
* `pd.NA`, `pd.NaT`, `Timestamp`, `datetime`, `date`, `Decimal`, NumPy `datetime64` → **proxies**. Call `->isoformat()`, `->timestamp()`, `->year`, `str(...)` on them, or format in pandas before copying: `$df['date']->dt->strftime('%Y-%m-%d')->tolist()`.
* `to_dict()` on a frame with NaN gives `NAN` floats inside the PHP array. `json_encode()` refuses `NAN`, so replace them first (`$df->fillna(...)` or `$df->astype('object')->where($df->notna(), null)`).

---

## Printed output and the log

Python has two kinds of output, and PyHP treats them differently.

**Text printed to `sys.stdout`** (`print()`) during a call is collected and delivered **when the call finishes**:

* `OutputMode::Echo` (default) — `echo`ed into PHP's output, so it shows up in the CLI or the HTTP response.
* `OutputMode::Capture` — kept in a buffer; read it with `$py->takeOutput()`.

Output printed before a Python exception is delivered too.

**Everything else goes to the worker log:** `sys.stderr`, `warnings`, logging handlers that write to stderr, output from C extensions writing directly to file descriptor 1 or 2, and output from background threads that print *between* calls. Read it with `$py->log()`. By default the log is a temp file deleted on `close()`. Set `log_file` to keep it.

Limits:

* **Output isn't streamed.** A 10-minute job's prints appear all at once when it ends. For progress reporting, write to the log, or to a file, from Python.
* **`input()` raises `EOFError`.** The worker has no stdin. Pass data in through `vars` or arguments.
* Captured output counts toward `max_message_bytes`.

---

## Errors

Everything PyHP throws extends `PyHP\Exception\PyHPException`.

| Exception | When | Session afterwards |
|---|---|---|
| `PythonException` | Your Python code raised. Has `pythonType` (`"ValueError"`), `pythonMessage`, and `pythonTraceback` (the full Python traceback, starting at your code). `getMessage()` is `"ValueError: message"`. | ✅ still usable |
| `ConversionException` | A value can't cross the boundary (PHP closure, `DateTime`, proxy from another session, strict `toPhp()` failure). | ✅ still usable |
| `MessageTooLargeException` | A request or result is bigger than `max_message_bytes`. | ✅ still usable |
| `ConfigException` | Invalid option (unknown key, wrong type, bad value). | n/a: thrown before starting |
| `WorkerStartException` | Python couldn't be started: interpreter not found, crashed during start-up (e.g. Python < 3.10), didn't connect within `startup_timeout`. The message includes the exit code and the worker log. | n/a: never started |
| `TimeoutException` | One call took longer than `timeout`. **The worker is killed** because a running Python call can't be interrupted safely. | ❌ closed, all state lost |
| `WorkerCrashedException` | The worker process died: `os._exit()`, a segfault in a native library, killed by the OS (out of memory). The message includes the log tail. | ❌ closed |
| `SessionClosedException` | You used a session, or a proxy, after `close()` or after a crash or timeout. | ❌ closed |
| `ProtocolException` | PHP and the worker disagree about the wire format, usually because the PHP and Python halves of PyHP come from different versions. | ❌ closed |

Notes:

* `sys.exit()` in your code does **not** stop the worker. It's reported as `PythonException` with type `SystemExit`. `os._exit()` does stop it, as `WorkerCrashedException`.
* `KeyboardInterrupt` and other `BaseException`s raised by your code are reported as `PythonException` too.
* There is **no automatic restart or retry.** If a session dies, start a new one and rebuild the state you need, because retrying could repeat side effects.
* After a crash or timeout the default session (`Py::default()`) starts a fresh worker on next use, **with empty state**.

```php
use PyHP\Exception\{PythonException, TimeoutException, PyHPException};

try {
    $py->functions->risky($input);
} catch (PythonException $e) {
    error_log($e->pythonTraceback);           // your bug, session still fine
} catch (TimeoutException $e) {
    $py = Py::session();                       // worker was killed: start over
} catch (PyHPException $e) {
    // any other PyHP problem
}
```

---

## Object lifetime and memory

* Each proxy keeps its Python object alive. When PHP frees the proxy (it goes out of scope, you `unset()` it, or the chain's temporary finishes), PyHP queues a release, which is **sent with the next request**. Destructors never wait on Python.
* Temporaries in a chain like `$df->groupby('g')['v']->sum()` are released automatically.
* Fetching the same Python object twice gives two proxies and two registry entries. Both are released independently, and the object lives until Python has no references left.
* **Leaks happen when you keep proxies around:** storing thousands of proxies in a long-lived PHP array keeps thousands of Python objects alive. `$py->info()['objects']` shows how many proxies the worker is holding.
* `close()` frees everything in the worker. Proxies that survive their session throw `SessionClosedException` when used.
* A `Session` isn't destroyed while any of its proxies still exist, because proxies reference it. Call `close()` explicitly when you're done instead of relying on destructors.
* On `close()`, PyHP closes the socket, waits up to 2 s for Python to exit cleanly, then kills it.

---

## Performance

Measured with [`benchmarks/overhead.php`](benchmarks/overhead.php). Run it yourself, without Xdebug coverage: `php benchmarks/overhead.php path\to\python.exe`.

| Operation | Linux (PHP 8.3, Python 3.13, 2 vCPU) | Windows (PHP 8.4, Python 3.11 venv) |
|---|---|---|
| Start a session (no imports) | ~36 ms | ~175 ms (Python alone needs ~120 ms there) |
| One round trip (`eval('1')`) | ~74 µs | ~87 µs |
| Attribute read on a proxy | ~63 µs | ~76 µs |
| `import pandas` (first time in a session) | ~190 ms | ~330 ms |
| 1,000,000-row `groupby().mean().to_dict()` via 4 proxy calls | ~16 ms | ~16 ms |
| …the same as one `eval()` | ~9 ms | ~13 ms |
| `close()` with pandas loaded | ~72 ms | ~73 ms |

Copying a 100,000-row × 4-column DataFrame into PHP (Linux):

| Method | Time | PHP memory peak | PHP result |
|---|---|---|---|
| `$df->to_dict('records')` | ~610 ms | ~60 MB | list of rows, 53 MB |
| [`Py::rows($df)`](#copying-tables-into-php) | ~240 ms | ~49 MB | list of rows, 41 MB |
| [`Py::columns($df)`](#copying-tables-into-php) | ~180 ms | ~16 MB | one list per column, 11 MB |

What that means:

* **Per-call overhead is negligible for DataFrame-level work.** Going through 4 proxies instead of one Python expression costs a few milliseconds on a 1M-row groupby.
* **Per-call overhead dominates chatty code.** 10,000 attribute reads ≈ 1 s. Don't loop over rows from PHP; let pandas/NumPy do it, or write the loop in Python.
* **Copying is the expensive part, in time and in memory.** Data travels as JSON, and PHP arrays are much bigger than a DataFrame. Use `Py::rows()`/`Py::columns()` for tables (2.5–3.5× faster than `to_dict('records')`; columns need a quarter of the memory), copy summaries rather than datasets, and for very large outputs write a file from Python (`to_csv`, `to_parquet`). PHP's default `memory_limit` of 128 MB (Windows `php.ini`) holds roughly 250k rows × 4 columns as rows, or over 1M as columns.
* **Start-up and imports are paid per session.** Reuse a session for many calls instead of creating one per call. Python itself starts more slowly on Windows (~120 ms on the test machine vs ~13 ms on Linux).

The per-call figure is in line with other process-based bridges. In-process embedding (swoole/phpy) is far faster per call; see [the comparison](#pyhp-vs-swoolephpy).

**Why not Apache Arrow/Parquet for transfers?** Reading Arrow in PHP needs a compiled extension or a slow pure-PHP reader, which would break PyHP's "just `composer require`" promise. Column-wise JSON plus a faster conversion walk gets most of the gain with nothing extra to install. If you need Arrow/Parquet, write the file from Python (`$df->to_parquet($path)`) and read it with your own tools.

---

## Where you can use it

| Environment | Works? | What to know |
|---|---|---|
| **CLI scripts, cron jobs, artisan commands** | ✅ Best fit | One session for the whole script. |
| **Queue workers, daemons, Octane/RoadRunner/FrankenPHP workers, NativePHP** | ✅ with care | Sessions persist across jobs/requests in the same PHP process, which avoids start-up cost but **shares Python state between jobs/requests**. Prefer explicit `Py::session()` objects with a clear owner over `Py::default()`. Call `Py::reset()` between units of work if they must not share state. |
| **PHP-FPM / Apache mod_php (classic web requests)** | ✅ but slow to start | Every request starts its own worker (~40 ms plus your imports, e.g. +200 ms for pandas). Workers can't be kept alive between requests in this model. Fine for occasional reports, not for hot endpoints. |
| **Concurrency** | ⚠️ | A session handles one call at a time and isn't thread-safe. Don't share one session between threads, fibers that interleave calls, or forked processes. |
| **`pcntl_fork()` with an open session** | ❌ | Parent and child would share one socket. Open sessions after forking. |
| **Shared hosting** | ⚠️ | Works only if `proc_open` is allowed and you can run Python there. |

---

## Security

* **Only run code you trust.** Python code run through PyHP can do anything your PHP process's user can do: read files, open network connections, start processes. A timeout doesn't contain it.
* **Never build Python source from user input.** Pass data as values, not code:

  ```php
  // ✗ Injection: user input becomes code
  $py->exec("name = '{$_GET['name']}'");

  // ✓ User input stays data
  $py->vars->name = $_GET['name'];
  $py->functions->process($_GET['name']);
  ```

* The worker listens on `127.0.0.1` only (never on the network), on a random port, and only until PHP has connected; then it closes the listener. The first message on a connection must contain a random 128-bit token that only the PHP process that started the worker knows. Any other connection is dropped without a reply, and a worker that nobody connects to within `startup_timeout` exits. Local processes running as **your own user** could still inspect the worker (environment variables, memory), as with any process.
* The token and PyHP's own variables (`PYHP_*`) are removed from `os.environ` before your code runs.
* PyHP never logs your code, arguments, or variables. The worker log contains only what Python writes to stderr.

---

## Platform support

| Platform | Status |
|---|---|
| **Linux** | ✅ Tested: PHP 8.3, Python 3.13 (pandas 3.0, NumPy 2.5). |
| **Windows** | 🎯 Primary target, **not yet run.** The design avoids the known Windows pitfalls: sockets instead of pipes (PHP can't wait on pipes with a timeout on Windows), no shell (an argument array goes to `proc_open`), `NUL` instead of `/dev/null`, `SYSTEMROOT` passed through. The included CI workflow runs the suite on Windows. Treat Windows as unverified until that passes. |
| **macOS** | Expected to work like Linux; **not tested yet**. GitHub Actions has macOS runners, so CI can test it without a Mac (see [Continuous integration](#continuous-integration)). |
| **32-bit PHP** | ❌ Not supported (PHP integers must be 64-bit). |
| **Python < 3.10** | ❌ The worker fails at start-up with a clear `WorkerStartException`. |

Process trees: when a worker is killed (timeout, `close()` after the grace period), **child processes your Python code started are not killed**, on any platform.

---

## Imports

`import()` works like Python's `import` statement: it imports, **binds the name in the session namespace**, and returns what it imported.

| PHP | Python | Name bound | Returns |
|---|---|---|---|
| `$py->import('pandas')` | `import pandas` | `pandas` | module proxy |
| `$py->import('pandas', as: 'pd')` | `import pandas as pd` | `pd` | module proxy |
| `$py->import('os.path')` | `import os.path` | `os` (like Python) | the `os.path` module |
| `$py->import('os.path', as: 'osp')` | `import os.path as osp` | `osp` | the `os.path` module |
| `$py->import('time', from: 'datetime')` | `from datetime import time` | `time` | class proxy |
| `$py->import('time', from: 'datetime', as: 't')` | `from datetime import time as t` | `t` | class proxy |
| `$py->import('pi', from: 'math')` | `from math import pi` | `pi` | `3.141592653589793` (plain data is copied, as everywhere) |
| `$py->import('util', from: 'importlib')` | `from importlib import util` | `util` | module proxy (submodules work) |
| `$py->import('*', from: 'math')` | `from math import *` | every public name (`__all__` if defined) | `null` |

After importing, everything is reachable from both sides: `$py->modules->pd`, `$py->classes->time`, `$py->vars->pi`, and plain Python code in `exec()`/`eval()`.

Errors are Python's own: `ModuleNotFoundError` for a missing module, `ImportError: cannot import name 'x' from 'y'` for a missing name, both as `PythonException`. An invalid alias, `'*'` without `from`, or `'*'` with `as` throws `ProtocolException` before anything runs.

### Is anything cached?

Yes, by Python itself, and only within a session:

* **The first import of a module in a session runs its code** (e.g. ~200–330 ms for pandas). Python then keeps the module in `sys.modules`, so **importing it again in the same session is almost free** (one round trip, ~0.1 ms) and returns the same module object.
* **A new session is a new Python process** and pays the first import again. That's why reusing one session for many calls matters.
* PHP doesn't cache proxies: each `$py->modules->pd` asks Python (~0.1 ms) and gets a fresh proxy to the same module. That keeps PHP in sync if Python code rebinds the name later. If you use a module in a hot loop, keep the proxy in a PHP variable: `$pd = $py->modules->pd;`.

---

## Every limitation in one list

**Execution model**

1. Python runs in a separate process. Nothing is shared in memory; values are copied through JSON, or referenced by ID.
2. One call at a time per session. Not thread-safe; don't share sessions across forks.
3. Every proxy operation is a round trip (~0.1 ms on Linux).
4. Sessions can't outlive their PHP process. Under PHP-FPM, every request pays start-up and import time.
5. Timeouts kill the worker and lose all session state. Running Python calls can't be interrupted gently.
6. Child processes started by your Python code are not killed with the worker.
7. No automatic restart or retry.

**Syntax gaps**

8. No Python operators on proxies (`+`, `>`, `&`, `~`, `@`, …). Use method forms or `eval()`.
9. No PHP closures as Python callbacks (`df.apply(fn)` with a PHP function). Define callables in Python.
10. `count($proxy)` doesn't work; use `Py::len()`. `if ($proxy)` is always true.
11. Proxy `==`/`===` compares proxies, not Python objects.
12. Python methods named `offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`, `getIterator`, or like PHP magic methods need `$o->__call('name', [...])`.
13. No `with` blocks, `async`/`await`, decorators, or `yield` from PHP: write those parts in Python.
14. `modules` shows what is bound; it never imports implicitly. `import()` without `as` binds the top-level package name, like Python.
15. `execFile()` doesn't run `if __name__ == "__main__":` blocks and doesn't add the script's folder to `sys.path`.

**Conversion**

16. Tuples come back as lists; empty dicts/lists/tuples all become `[]`; dicts with keys `0..n` become lists.
17. PHP's numeric-string key normalisation applies (`{'1': x}` → `[1 => x]`).
18. Python ints beyond 64 bits, sets, dates, Decimals, `pd.NA`/`NaT` and subclasses of built-ins stay proxies.
19. PHP objects (`DateTime`, enums, DTOs) and resources can't be sent; convert them first.
20. Non-UTF-8 PHP strings become `bytes`; Python `bytes` come back as plain strings.
21. Everything copied goes through JSON. Large copies are slow and capped by `max_message_bytes` (64 MiB default); `Py::rows()`/`Py::columns()` are the fastest way to copy tables.

**Output and diagnostics**

22. Printed output arrives when the call ends, not live.
23. `input()` can't read anything.
24. Output from background threads between calls goes to the log, not to PHP.

**Environment**

25. Windows and macOS not yet verified (see [Platform support](#platform-support)).
26. 64-bit PHP 8.2+ and Python 3.10+ only.
27. Not a sandbox. Trusted code only.
28. `multiprocessing` from session code is untested. Functions defined inside the session (rather than in importable modules) can't be pickled into child processes on Windows/macOS (`spawn`).

---

## How it works inside

```
 PHP process                                     Python worker (python -u pyhp_worker.py)
┌──────────────────────────────┐               ┌───────────────────────────────────────┐
│ Py / Session / Vars /        │  127.0.0.1    │ Session                               │
│ Functions / PyObject         │◄─────────────►│  ├─ namespace (module "__pyhp__")     │
│   │                          │  TCP socket   │  ├─ registry: id → Python object      │
│   ├─ Codec: PHP ⇄ JSON       │  length-      │  ├─ codec: Python ⇄ JSON              │
│   └─ Worker: proc_open,      │  prefixed     │  └─ handlers: exec, eval, getattr,    │
│      socket, timeouts        │  JSON frames  │     call_method, getitem, iter, …     │
└──────────────────────────────┘               │ stdout/stderr ──► log file            │
                                               │ stdin ◄── /dev/null (NUL)             │
                                               └───────────────────────────────────────┘
```

1. **Start:** PHP starts `python -u pyhp_worker.py` with `proc_open()` and an argument array, with no shell involved. The token, limits and the path of a temporary *port file* are passed in environment variables.
2. **Handshake:** the worker listens on `127.0.0.1` (random port) and writes the port to the port file. PHP connects and sends `{"op": "auth", "token": …}`. The worker checks the token, closes its listener, and answers `hello` with the protocol version, which PHP checks. The worker listens rather than PHP because, on Windows, PHP waits 500 ms every time it closes a listening socket.
3. **Requests:** each frame is a 4-byte big-endian length plus UTF-8 JSON, e.g. `{"op": "call_method", "ref": 7, "name": "head", "args": [3], "kwargs": {}}`. The worker runs it, captures `print()` output, and answers `{"ok": true, "value": …, "out": "…"}` or `{"ok": false, "error": {"kind", "type", "message", "traceback"}}`.
4. **Values:** JSON natives travel as-is, and Python dicts travel as plain JSON objects, so PHP's `json_decode()` produces the final array directly. Every response carries a `tagged` flag; when it's `false` (no references, bytes or NaN/Inf anywhere in the value), PHP uses that array as-is, without a second pass or a second copy. Other values are tagged objects: `{"$t": "ref", "id": 7, "type": "pandas.DataFrame"}`, `{"$t": "bytes", "v": "<base64>"}`, `{"$t": "float", "v": "nan"}`, and `{"$t": "dict", "o": {…}}` for the rare Python dict that itself has a `"$t"` key. From PHP to Python, dicts travel as `{"$t": "dict", "v": [[key, value], …]}` so integer keys stay integers, plus `{"$t": "tuple", …}` and `{"$t": "slice", …}`.
5. **Releases:** IDs of freed proxies ride along on the next request (`"release": [7, 9]`).
6. **Timeouts:** PHP waits with `stream_select()` against one deadline per call. On expiry the worker is killed.

The protocol is versioned (`protocol: 1`). A mismatch between PHP and Python halves fails at the handshake.

---

## PyHP vs. swoole/phpy

[swoole/phpy](https://github.com/swoole/phpy) embeds CPython inside PHP through a C extension: PHP and Python share one process, and calls are C function calls.

| | PyHP | swoole/phpy |
|---|---|---|
| Install | `composer require` | Compile and enable a PHP extension (and build against your Python) |
| Speed per call | ~0.1 ms round trip | Far faster (direct C calls) |
| Python crash | PHP gets an exception, keeps running | Takes the PHP process down |
| Hard timeouts | Yes (kills the worker) | No clean way to stop a running call |
| Python environment | Any interpreter/venv per session | The one it was built and linked with |
| Data | Copied through JSON or referenced by ID | Shared in memory |

**Choose phpy** when you need maximum speed for fine-grained interop and you control the server build. **Choose PyHP** when you want zero setup, crash isolation, timeouts, per-project virtual environments, or you can't install extensions (NativePHP, managed hosting, Windows desktops).

---

## Development

```
PyHP/
├── src/                       PHP library (namespace PyHP\)
│   ├── Py.php                 static facade + helpers
│   ├── Session.php            one worker + namespace
│   ├── Vars.php, Functions.php
│   ├── PyObject.php           the proxy
│   ├── Config.php, OutputMode.php
│   ├── Value/                 Tuple, Slice, Dict markers
│   ├── Exception/             exception hierarchy
│   └── Internal/              Worker (process + socket), Codec (wire format)
├── python/pyhp_worker.py      the worker (standard library only)
├── tests/php/                 Pest tests (need a real interpreter)
├── tests/python/              pytest tests for the worker
├── examples/                  runnable examples + sample data
├── benchmarks/                overhead.php, startup-trace.php
├── studies/                   feasibility studies for possible features
└── docs/PROJECT_CONTEXT.md    design decisions and roadmap
```

### PHP checks (Windows)

```bat
composer install
set PYHP_TEST_PYTHON=C:\path\to\.venv\Scripts\python.exe
vendor\bin\pint --test
vendor\bin\phpstan analyse
vendor\bin\pest --coverage --min=80
```

Or all at once: `composer check`. Coverage needs PCOV or Xdebug. The pandas/NumPy tests are skipped automatically if those packages aren't installed in the interpreter under test.

### Python checks

```bat
python -m pip install pytest pytest-cov mypy ruff numpy
python -m ruff format --check python tests/python
python -m ruff check python tests/python
python -m mypy
python -m pytest --cov
```

### Continuous integration

`.github/workflows/ci.yml` runs every check on GitHub Actions for each push and pull request: the Python worker on Python 3.10 and 3.13, and the PHP suite on PHP 8.2 and 8.4 with pandas installed, on Linux and Windows.

To test macOS too, add `macos-latest` to both `os:` lists:

```yaml
        os: [ubuntu-latest, windows-latest, macos-latest]
```

macOS runners are free for public repositories. For private ones they use your Actions minutes at a higher rate than Linux (check GitHub's current billing page), so you may prefer to run them only on pull requests.

### Rules the code follows

TDD, 80% minimum coverage, `declare(strict_types=1)` everywhere, PHPStan level max, `mypy --strict`, Ruff (`E,F,W,I,B,UP,N,S,ANN,SIM,RUF`), and fail-loudly exceptions. The worker stays standard-library-only.

---

## Roadmap

See [`docs/PROJECT_CONTEXT.md`](docs/PROJECT_CONTEXT.md) for the full plan. Next up:

1. **Verify on Windows**, then fix whatever it finds.
2. ~~Imports, `modules` and `classes`~~ — done.
3. ~~Faster bulk data~~ — done (`Py::rows()`, `Py::columns()`, faster conversion).
4. **PHP callbacks** (`df.apply($phpClosure)`) — see the [feasibility study](studies/php-callbacks.md).
5. **macOS CI** (one line in `ci.yml`, see [Continuous integration](#continuous-integration)).
6. Packagist release, after a code review and choosing a license.

## License

Not chosen yet. Until a license is added, all rights are reserved by the author.
