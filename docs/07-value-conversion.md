# 7. How values are converted

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
| `Closure` (also `strlen(...)` first-class callables) | a callable `pyhp.PhpCallable` | See [PHP callbacks](09-php-callbacks.md#php-callbacks). It comes back to PHP as the same `Closure`. |
| callable strings and arrays (`'strlen'`, `[$obj, 'method']`) | `str` / `list` | **Never** treated as callables: data stays data. Wrap them: `strlen(...)`, `$obj->method(...)`. |
| any other object (`DateTime`, enums, your classes) | ✗ `ConversionException` | Convert first: `$date->format('c')`, `$enum->value`, `(array) $dto`. |
| resource | ✗ `ConversionException` | |
| arrays nested deeper than 256 levels | ✗ `ConversionException` | |

### Python → PHP (results)

| Python value | PHP value | Notes |
|---|---|---|
| `None` | `null` | |
| `bool`, `numpy.bool_` | `bool` | |
| `int` within 64-bit range, NumPy integers, other whole-number types (`sympy.Integer`, anything registered as `numbers.Integral`) | `int` | Exact, so nothing is lost. |
| whole numbers outside 64-bit range | **proxy** | e.g. `2**70` — no silent precision loss. |
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
* **Copying is recursive and complete.** Reading a list of a million items copies a million items. Keep big data in Python and copy small results; [`max_message_bytes`](05-api-reference.md#configuration-options) stops runaway copies.
* **Cycles are cut:** a list that contains itself comes back with a proxy where the cycle closes.

### `Py::toPhp()` (strict conversion)

`Py::toPhp($proxy)` copies the whole object or fails. It accepts everything automatic conversion accepts, plus `set`/`frozenset` (as lists) and subclasses of built-in types. It throws `ConversionException` instead of producing nested proxies, and also on cycles or nesting deeper than 256 levels. Use it when you need to be sure you have plain PHP data, for example before `json_encode()`.

---

← [Imports](06-imports.md) · [Contents](README.md) · [pandas and NumPy](08-pandas-and-numpy.md) →
