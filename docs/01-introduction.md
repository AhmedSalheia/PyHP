# 1. Introduction

## What PyHP is — and what it is not

PyHP starts **one separate Python process per session** (the *worker*) and talks to it over a private local socket. Your Python objects live in that process. PHP gets lightweight **proxies** that forward every attribute read, method call, index and loop to the real object.

That design makes some things easy and some things impossible, and it's worth knowing which before you start:

| PyHP is | PyHP is not |
|---|---|
| A pure Composer package: no PHP extension, no compiling | An embedded interpreter. Python does **not** run inside the PHP process |
| Any Python 3.11+ you choose: system, venv, conda | A hidden installer. Sessions never install anything; `vendor/bin/pyhp setup` builds the project's environment only when you run it |
| Crash-isolated: if Python dies, PHP gets an exception and keeps running | Fast at chatty, fine-grained calls (each call is a round trip, ~0.1 ms) |
| Stateful: imports and variables survive between calls in a session | Shared between web requests (one PHP-FPM request = its own session) |
| For trusted code you wrote | A sandbox. Never run user-submitted Python with it |

**The rule of thumb:** work on whole objects (a DataFrame, an array) with a few calls, and let Python do the heavy lifting. Don't loop over a million rows from PHP.

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

More complete, runnable examples are in [`examples/`](../examples):

| File | Shows |
|---|---|
| `01_basics.php` | exec, eval, printed output, catching Python errors |
| `02_vars_and_functions.php` | `execFile`, sharing variables, keyword arguments, `classes`, Python objects as proxies |
| `03_pandas_report.php` | a full pandas report written in PHP syntax |
| `04_numpy.php` | NumPy arrays, tuple indexes, slices, scalars |
| `05_callbacks.php` | PHP closures called from Python: `sorted(key=...)`, pandas `apply`, exceptions |
| `06_sympy.php` | SymPy: solving, calculus, LaTeX, exact values, `lambdify`, matrices |
| `07_spacy_nlp.php` | spaCy: entities, sentences, lemmas, the Matcher, `nlp.pipe`, a PHP pipeline component, displaCy HTML |
| `08_standard_library.php` | No installs: "did you mean" and diffs (`difflib`), `statistics`, `itertools`, exact `Fraction`/`Decimal`, `Counter`, `ipaddress` |
| `09_matplotlib_charts.php` | matplotlib: a chart from PHP arrays as a PNG file, an inline SVG for a web page, and PNG bytes for an HTTP response |
| `10_pillow_images.php` | Pillow: thumbnails, square avatars, watermarks, WebP bytes in memory, images from PHP strings |
| `11_scikit_learn.php` | scikit-learn: train on PHP arrays, measure accuracy, predict probabilities, save and reload the model with joblib |

To run them from a clone of PyHP, install their Python packages once (into `.venv`, which PyHP then finds by itself), then run any example:

```bat
php bin/pyhp setup
php examples\09_matplotlib_charts.php
```

`bin/pyhp setup` reads `extra.pyhp` in PyHP's own `composer.json`, which points at `requirements-dev.txt`: every library the examples and optional tests use, plus the Python dev tools. Each example lists the one package it needs, if you'd rather install less.

---

[Contents](README.md) · [Installation](02-installation.md) →
