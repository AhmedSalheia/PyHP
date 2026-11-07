# PyHP

[![CI](https://github.com/pyhp-php/pyhp/actions/workflows/ci.yml/badge.svg)](https://github.com/pyhp-php/pyhp/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/pyhp/pyhp)](https://packagist.org/packages/pyhp/pyhp)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

**Use Python libraries (pandas, NumPy, spaCy, scikit-learn, anything) from PHP, in PHP syntax, without a compiled extension.**

PyHP runs a Python worker next to your PHP code and gives you PHP objects that stand for Python ones. You call Python functions, read and write Python variables, pass PHP closures into Python, and get results back as plain PHP values. Python and PHP share one context for the whole session, so you work step by step, just like in a Python script.

```php
$pd = $py->import('pandas');

$sales  = $pd->read_csv('sales.csv');
$big    = $sales[$sales['revenue']->ge(5000)];
$totals = $big->groupby('region')['revenue']->sum()->sort_values(ascending: false);

print_r($totals->to_dict());                      // ['west' => 306861.5, ...]
```

## Install

```bat
composer require pyhp/pyhp
```

List the Python packages your project needs in `composer.json`:

```json
"extra": { "pyhp": { "python": "3.12", "requirements": ["pandas>=2"] } }
```

and let PyHP build the Python environment (with [uv](https://docs.astral.sh/uv/) installed it can even download Python for you):

```bat
vendor/bin/pyhp setup
vendor/bin/pyhp info      # check that everything is in place
```

PyHP finds that environment by itself. Requirements: PHP 8.2+ (64-bit) and Python 3.10+. See [Installation](docs/02-installation.md) for every option.

## A few examples

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

Python objects stay in Python; you hold proxies to them and call their methods with PHP syntax, including named arguments:

```php
$np = $py->import('numpy');
$matrix = $np->array([[1, 2], [3, 4]]);
printf('%.1f', $np->linalg->det($matrix));       // -2.0

$py->import('Matcher', from: 'spacy.matcher');   // Python-style imports
```

PHP closures work anywhere Python expects a function:

```php
$names = $pd->Series(['ahmed', 'lina']);
$names->apply(ucfirst(...))->tolist();           // ['Ahmed', 'Lina']
```

Runnable examples are in [`examples/`](examples):

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

## Documentation

The [`docs/`](docs) folder explains everything, including each limitation. The files are numbered in reading order:

1. [Introduction](docs/01-introduction.md)
2. [Installation](docs/02-installation.md)
3. [The pyhp command](docs/03-command-line.md)
4. [Core concepts](docs/04-core-concepts.md)
5. [API reference](docs/05-api-reference.md)
6. [Imports](docs/06-imports.md)
7. [How values are converted](docs/07-value-conversion.md)
8. [pandas and NumPy](docs/08-pandas-and-numpy.md)
9. [PHP callbacks](docs/09-php-callbacks.md)
10. [Using other Python libraries](docs/10-libraries.md)
11. [Output and errors](docs/11-output-and-errors.md)
12. [Memory and performance](docs/12-memory-and-performance.md)
13. [Deployment, security and platforms](docs/13-deployment-and-security.md)
14. [Limitations](docs/14-limitations.md)
15. [How PyHP works inside](docs/15-internals.md)
16. [Development](docs/16-development.md)

## Contributing

Contributions are welcome: bug reports, examples for more libraries, fixes and features.

* Read [Development](docs/16-development.md) for the project layout, the checks and the Git hooks. `php bin/pyhp setup` in a clone installs every Python package the tests and examples use.
* Work on a branch from `develop` (`feature/…`, `fix/…`, `chore/…`) and open a pull request into `develop`. `main` only receives releases.
* Write the test first, keep PHPStan (level max), Pint, Ruff and mypy happy, and keep coverage above 80%.
* Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, …).
* Add a line for every user-visible change to [`CHANGELOG.md`](CHANGELOG.md) under **[@dev]**; it moves under a version number when that version is released.

## About the project

PHP powers a huge part of the web, but the best tools for data analysis, scientific computing, natural language processing and machine learning live in Python. PyHP's goal is to make those tools usable from PHP today, by PHP developers who may not know Python well, until someone builds native PHP versions of NumPy and pandas.

The design choices follow from that goal: no compiled extension to install, the same code on Linux, Windows and macOS, a context shared between PHP and Python instead of one-off calls, and documentation that is honest about every limitation. It's also meant to be a tool that's enjoyable to build and to use.

The plan for the next versions is in [`ROADMAP.md`](ROADMAP.md).

## About the author

PyHP is created and maintained by **Ahmed Salheia** ([GitHub](https://github.com/AhmedSalheia)).

<!-- TODO(Ahmed): add a short bio and links (website, LinkedIn, X/Twitter, email) here. -->

## Support the project

If PyHP saves you time, you'll soon be able to support its development here. Donation links are coming.

<!-- TODO(Ahmed): add donation links when they exist, e.g. GitHub Sponsors, Ko-fi, Open Collective, PayPal.
     Also add them to .github/FUNDING.yml so GitHub shows a "Sponsor" button. -->

## License

PyHP is open-source software licensed under the [MIT license](LICENSE).
