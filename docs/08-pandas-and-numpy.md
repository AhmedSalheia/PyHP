# 8. pandas and NumPy

## pandas and NumPy in practice

### The pattern

1. **Load and transform inside Python**, through proxies or `exec()`.
2. **Copy only the result** to PHP: `->to_dict()`, `->item()`, a scalar like `->sum()`, a whole table with [`Py::rows($df)`](#copying-tables-into-php), or a whole array with [`Py::toPhp($array)`](#numpy-arrays).
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
| `lambda x: x * 2` | a PHP closure: `fn ($x) => $x * 2` (see [PHP callbacks](09-php-callbacks.md#php-callbacks)), or a Python lambda via `exec()` when speed matters |

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

### NumPy arrays

`Py::toPhp()` copies a NumPy array into nested PHP arrays. Arrays of numbers and bools travel as raw bytes instead of JSON, which is 3–10× faster than `->tolist()` for big arrays:

```php
$np = $py->import('numpy');
$image = $np->random->rand(1000, 1000);

$pixels = Py::toPhp($image);      // [[0.41, 0.07, ...], ...], 1000 rows of 1000 floats
```

`Py::ndarray()` goes the other way: it sends a PHP array as a NumPy array, packed as bytes in PHP:

```php
$model->predict(Py::ndarray($features));              // float64
$py->vars->mask = Py::ndarray($bits, 'bool');
$py->vars->img = Py::ndarray($rgbRows, 'uint8');
```

* The data must be rectangular lists: every row at the same depth has the same length, and keys are `0, 1, 2, ...` (use `array_values()` on other arrays).
* `dtype` is one of `float64` (the default), `float32`, `int64`, `int32`, `int16`, `int8`, `uint32`, `uint16`, `uint8`, `bool`. Values must fit: ints in range for int types, ints or floats for float types, bools for `bool`. Anything else throws `ConversionException` saying what was expected.
* Python gets an ordinary writable array.

What `Py::toPhp()` makes of each kind of array:

| NumPy array | PHP value |
|---|---|
| `float64`, `float32`, `float16`, `longdouble` | floats (`NAN`, `INF` included; `longdouble` is rounded to `float64`) |
| `int8` … `int64`, `uint8` … `uint32` | ints |
| `uint64` | ints, or `ConversionException` if a value is above `PHP_INT_MAX` |
| `bool` | bools |
| strings, objects | copied value by value, like a list (slower) |
| complex, `datetime64`, `timedelta64` | `ConversionException`; convert first, e.g. `->astype('str')` |
| 0-dimensional (`np.array(5)`) | the single value |
| empty dimensions (`np.zeros((3, 0))`) | `[[], [], []]` |

Arrays inside other values (`Py::toPhp($dict)` where the dict holds arrays) are copied the same way. Everywhere else, for example as the result of `eval()`, an array stays a proxy as before. Each array has to fit in `max_message_bytes` (64 MiB by default, about 8 million `float64` values); raise it for bigger arrays.

### Missing values and dates

* `numpy.nan` and float NaN → PHP `NAN`. Test with `is_nan()`, not `=== NAN`.
* `pd.NA`, `pd.NaT`, `Timestamp`, `datetime`, `date`, `Decimal`, NumPy `datetime64` → **proxies**. Call `->isoformat()`, `->timestamp()`, `->year`, `str(...)` on them, or format in pandas before copying: `$df['date']->dt->strftime('%Y-%m-%d')->tolist()`.
* `to_dict()` on a frame with NaN gives `NAN` floats inside the PHP array. `json_encode()` refuses `NAN`, so replace them first (`$df->fillna(...)` or `$df->astype('object')->where($df->notna(), null)`).

---

← [How values are converted](07-value-conversion.md) · [Contents](README.md) · [PHP callbacks](09-php-callbacks.md) →
