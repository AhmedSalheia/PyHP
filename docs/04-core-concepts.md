# 4. Core concepts

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

The exact rules are in [How values are converted](07-value-conversion.md#how-values-are-converted).

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

← [The pyhp command](03-command-line.md) · [Contents](README.md) · [API reference](05-api-reference.md) →
