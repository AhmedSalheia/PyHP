# 9. PHP callbacks

## PHP callbacks

PHP closures can be passed to Python wherever Python expects a function. Python calls them, PHP runs them, and Python carries on with the result:

```php
$sorted = $py->functions->sorted($people, key: fn (array $p) => $p['age']);

$names = $pd->Series(['ahmed', 'sam']);
$names->apply(ucfirst(...))->tolist();                 // ['Ahmed', 'Sam']

$py->vars->scale = fn (float $x, float $by = 1.0) => $x * $by;
$py->eval('scale(2.0, by=1.5)');                       // 3.0: keyword arguments become named arguments

$py->modules->scipy->optimize->minimize(fn ($x) => cost($x), x0: [1, 2]);   // any library that takes a function
```

### What gets passed

* **Only `Closure` objects** become Python callables: `fn () => ...`, `function () {...}`, and first-class callables like `strlen(...)` or `$obj->method(...)`. Strings and arrays are never treated as callables, even if PHP's `is_callable()` would accept them, so a string like `"system"` in your data can never become something Python can call.
* **Arguments** follow the usual [conversion rules](07-value-conversion.md#how-values-are-converted): plain data is copied, everything else arrives as a `PyObject` proxy. Python keyword arguments arrive as PHP named arguments.
* **The return value** is converted back the same way (arrays, scalars, proxies, `Py::tuple()`, ...).
* **The same closure** passed twice is the same Python object, and a closure Python hands back arrives in PHP as the original `Closure`.

### Callbacks that call Python

A callback can use the session freely: read `vars`, call `functions`, create proxies. Python serves those calls while it waits for the callback, so these nest:

```php
$py->exec('def through(f, n): return f(n) + 1');
$inner = fn (int $n) => $py->vars->base + $n;                    // PHP → Python, inside a callback
$outer = fn (int $n) => $py->functions->through($inner, $n * 2); // and another level
$py->functions->through($outer, 1);
```

Nesting is limited to **32 levels** (PHP → Python → PHP → ...); deeper raises `RecursionError` in Python, which reaches PHP as `PythonException`.

### Exceptions

* If a callback throws and Python **doesn't** catch it, PHP rethrows **the original exception object**, so `catch (YourDomainException $e)` keeps working through Python:

  ```php
  try {
      $py->functions->process($rows, fn ($row) => throw new InvalidRow($row));
  } catch (InvalidRow $e) {        // the exact object the callback threw
  }
  ```

* Python code can catch it as `PhpError` (with `php_class` and `php_message`):

  ```python
  from pyhp import PhpError

  try:
      callback(row)
  except PhpError as e:
      print(e.php_class, e.php_message)
  ```

* A return value PHP can't send (e.g. a `DateTime`) fails like an exception thrown inside the callback.

### Timeouts, threads and lifetime

* **Time spent running PHP callbacks doesn't count against `timeout`.** The timeout exists to stop Python that hangs; a slow PHP closure isn't that. (Python's own time still counts, and nested calls have their own timeout.)
* **Callbacks must be called from the thread that serves PHP's requests, while PHP is waiting on a call.** A callback called from another Python thread (`threading`, `ThreadPoolExecutor`, some ML libraries' background threads) raises `RuntimeError` in that thread. A callback stored in Python and called during a *later* call works fine.
* PHP keeps a closure alive while Python holds it, and lets it go when Python drops it. `$py->info()['callbacks']` shows how many Python holds.
* Closing the session inside a callback ends the outer call with `SessionClosedException` (or with the exception the callback threw).

### Speed: one callback per batch, not per value

Each callback is one round trip (~65 µs on Linux), the same as any other call. Fine for an optimizer objective called 200 times; slow for a million rows. Three ways to transform 10,000 values, fastest last:

| | 10,000 values |
|---|---|
| `$series->apply(fn ($x) => $x * 2)`: one callback per value | ~780 ms |
| `$series->pipe(Py::vectorize(fn ($x) => $x * 2, elementwise: true))`: one callback per 1,000 values | ~12 ms |
| `$series->pipe(fn (PyObject $s) => $s->mul(2))`: one callback, pandas does the loop | ~1.4 ms |

#### `Py::vectorize()`: PHP logic, a round trip per batch

When the logic has to stay in PHP (your own validation rules, a PHP library, a lookup in your app), `Py::vectorize()` wraps the closure so Python sends it values in batches instead of one at a time:

```php
use PyHP\Py;

// Per value: write the closure as usual and add elementwise: true.
$clean = $names->pipe(Py::vectorize(fn (string $name) => ucfirst(trim($name)), elementwise: true));

// Per batch: the closure gets a PHP list and returns a list of the same length.
$scores = $texts->pipe(Py::vectorize(
    fn (array $batch): array => $sentiment->scoreMany($batch),
    batchSize: 500,
));
```

* Use it with `->pipe()`, which hands the whole Series to the wrapped closure. Any Python function that calls `f(data)` once works too.
* A pandas Series comes back as a Series with the **same index and name**, so it lines up with its DataFrame: `$df['clean'] = $df['name']->pipe(...)`. A NumPy array comes back as an array; anything else iterable (a list, a tuple, a generator) as a list.
* The batch closure must return exactly one value per input value, as a list. After `array_filter()` use `array_values()`; a wrong count raises `ValueError` in Python, which arrives as a `PythonException`.
* `batchSize` defaults to 1,000 values. Bigger batches mean fewer round trips but more memory per call; past a few thousand there's little left to gain.
* The value `Py::vectorize()` returns can be reused for many columns and sessions.

When the logic doesn't need PHP, write it with pandas methods or in Python: that's still ten times faster than any callback.

---

← [pandas and NumPy](08-pandas-and-numpy.md) · [Contents](README.md) · [Using other Python libraries](10-libraries.md) →
