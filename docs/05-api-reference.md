# 5. API reference

## API reference

### `Py` (static facade)

| Method | What it does |
|---|---|
| `Py::config(array $options)` | Change the configuration used by sessions created **from now on**. Options are merged into the current configuration. |
| `Py::configuration(): Config` | The current configuration. |
| `Py::session(array $overrides = []): Session` | A new independent session. `$overrides` apply to this session only. |
| `Py::default(): Session` | A shared default session, started on first use and restarted automatically if it was closed. |
| `Py::reset(bool $forgetConfig = false)` | Close the default session. With `forgetConfig: true` also go back to default options. |
| `Py::shared(string $name = 'default', ?Closure $setup = null, array $overrides = []): Session` | One long-lived session per name for this PHP process, with `restart` on and `$setup` as its `onStart` hook. Later calls return the same session. See [Long-running PHP](13-deployment-and-security.md#long-running-php-recovery-and-shared-sessions). |
| `Py::closeShared(?string $name = null)` | Close and forget one shared session, or all of them. |
| `Py::exec()`, `Py::execFile()`, `Py::eval()`, `Py::import()`, `Py::vars()`, `Py::functions()`, `Py::modules()`, `Py::classes()` | Shortcuts to the same methods on `Py::default()`. |
| `Py::tuple(...$items)` | A value sent as a Python `tuple`. |
| `Py::slice($start, $stop = null, $step = null)` | A value sent as a Python `slice`: `Py::slice(1, 3)` is `1:3`, `Py::slice(null, null, -1)` is `::-1`. |
| `Py::dict(array $items)` | A value sent as a Python `dict`, even if the array is empty or list-shaped. |
| `Py::len(PyObject $o): int` | Python `len(o)`. |
| `Py::repr(PyObject $o): string` | Python `repr(o)`. |
| `Py::type(PyObject $o): string` | The Python type, e.g. `pandas.DataFrame`, `int`, `__pyhp__.Invoice`. |
| `Py::ref(PyObject $o, string $attribute): PyObject` | A live proxy to an attribute instead of a copy, so you can change it in place: `Py::ref($doc, 'user_data')['k'] = 'v'`. |
| `Py::toPhp(PyObject $o): mixed` | Copy the object into PHP, also accepting sets and subclasses of dict/list/str/int/float. Throws instead of returning nested proxies. |
| `Py::rows(PyObject $df): array` | A pandas DataFrame as a list of rows, fast and `json_encode()`-ready; see [Copying tables into PHP](08-pandas-and-numpy.md#copying-tables-into-php). |
| `Py::columns(PyObject $df): array` | The same as one list per column: the most compact copy. |

> `Py::config()` does **not** change a default session that is already running. Call `Py::reset()` after changing options if you want the default session to pick them up.

### Configuration options

Use these keys with `Py::config()`, `Py::session()` and `Config::fromArray()`. Unknown keys and wrong types throw `ConfigException` immediately, so a typo doesn't fail silently.

| Option | Type | Default | Meaning |
|---|---|---|---|
| `python` | string | see [Which Python PyHP uses](02-installation.md#which-python-pyhp-uses) | The interpreter: a command on `PATH` or a full path. Point it at a venv's interpreter to use that venv. |
| `timeout` | float\|null | `30` | Seconds **one call** may take. `null` = wait forever. On timeout the worker is killed (see [Errors](11-output-and-errors.md#errors)). |
| `startup_timeout` | float | `10` | Seconds to wait for the worker to start and connect. |
| `cwd` | string\|null | `null` (PHP's) | Working directory of the Python process. Relative paths *inside Python* resolve against it. |
| `env` | array<string,string> | `[]` | Extra environment variables for Python. |
| `inherit_env` | bool | `true` | Start from PHP's environment (`true`) or from only `env` (`false`). On Windows `SYSTEMROOT` is always passed, because Python needs it. |
| `paths` | list<string> | `[]` | Extra folders added to Python's `sys.path`, so your own modules can be imported. |
| `output` | `OutputMode` or `'echo'`/`'capture'` | `Echo` | What happens to text Python prints. See [Printed output](11-output-and-errors.md#printed-output-and-the-log). |
| `max_message_bytes` | int | 64 MiB | Largest request or result allowed. Protects PHP from accidentally copying huge data. Minimum 1024. |
| `log_file` | string\|null | `null` (temp file) | Where the worker's stderr goes. A temp file is deleted when the session closes; set a path to keep it. |
| `iter_batch_size` | int | `100` | Items fetched per round trip in `foreach`. |
| `restart` | bool | `false` | After a crash or timeout, start a fresh worker on the next call and run the `onStart` hooks, instead of leaving the session closed. |

Precedence: built-in defaults → `Py::config()` → `Py::session([...])` overrides.

You can also build a `Config` directly with named arguments: `new Session(new Config(python: '...', timeout: 5))`.

### `Session`

| Method | What it does |
|---|---|
| `exec(string $code): void` | Run Python statements in the session namespace. |
| `execFile(string $path): void` | Run a `.py` file **in the session namespace**: what it defines becomes available through `vars` and `functions`. Relative paths are resolved from PHP's current directory. |
| `eval(string $expression): mixed` | Evaluate one expression and return its value. |
| `import(string $module, ?string $as = null, ?string $from = null): mixed` | Import like Python and bind the name in the session; see [Imports](06-imports.md#imports). |
| `$modules` | Modules bound in the session (see below). |
| `$classes` | Python classes, to create instances (see below). |
| `$vars` | Global variables (see below). |
| `$functions` | Global callables (see below). |
| `takeOutput(): string` | In capture mode, the text printed since the last call to `takeOutput()`; then clears it. |
| `log(): string` | Everything the worker wrote to stderr so far (warnings, crash output). |
| `info(): array` | `protocol`, `python` (version), `executable`, `objects` (live proxies), `callbacks` (PHP closures Python holds), `pid`. |
| `isOpen(): bool` | Whether the worker is running. |
| `ping(): bool` | Whether the worker answers a request (restarting it first when `restart` is on). |
| `onStart(Closure $hook): self` | Runs `$hook($session)` now and after every restart: put imports, models and variables the session needs here. |
| `restart(): void` | Replace the worker with a fresh one and run the `onStart` hooks. Proxies from before throw `StaleProxyException`. Not allowed inside a PHP callback. |
| `close(): void` | Stop the worker. Safe to call twice. Also happens automatically when the session object is destroyed. |
| `config(): Config` | The session's configuration. |

A few `Session` methods (`request()`, `codec()`, `release()`) are public for PyHP's internal use and are marked `@internal`. Don't call them; they may change without notice.

#### `exec` vs `eval` vs `execFile`

* `exec('x = 1')` runs **statements** and returns nothing. `exec('1 + 1')` computes and discards the result.
* `eval('x + 1')` evaluates **one expression** and returns it. `eval('x = 1')` is a `SyntaxError`.
* An expression spread over several lines must be wrapped in parentheses, exactly as in a Python file. Without them Python reads each new line as a new statement and fails with *"IndentationError: unexpected indent"*:

  ```php
  $monthly = $py->eval(<<<'PY'
      (
          orders.groupby('month')
          .agg(revenue=('revenue', 'sum'))
          .reset_index()
      )
      PY);
  ```

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
* **Modifying a copied value in place does nothing in Python**, and PHP warns you: `$py->vars->items[] = 3` gives *"Indirect modification of overloaded property … has no effect"*. Either assign the whole value back (`$py->vars->items = [...]`) or use a proxy: `$py->vars->ref('items')->append(3)`. The same goes for attributes of Python objects: `$obj->settings['debug'] = true` changes a copy, `Py::ref($obj, 'settings')['debug'] = true` changes Python's dict.

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

← [Core concepts](04-core-concepts.md) · [Contents](README.md) · [Imports](06-imports.md) →
