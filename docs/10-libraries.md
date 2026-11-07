# 10. Using other Python libraries

## SymPy and spaCy in practice

Any library works the same way: import it, call it through proxies, copy plain results into PHP. These two show the typical gotchas. Both are tested in CI (on Linux) by `tests/php/SympyTest.php` and `tests/php/SpacyTest.php`, which skip when the library isn't installed.

### SymPy (symbolic math)

```php
$sp = $py->import('sympy', as: 'sp');
$x = $sp->symbols('x');

$sp->solve($sp->sympify('x**2 - 5*x + 6'), $x);         // [proxy 2, proxy 3]
(string) $sp->diff($sp->sympify('x**3'), $x);            // '3*x**2'
$sp->latex($sp->Integral($sp->sqrt($x), $x));            // '\int \sqrt{x}\, dx'
$f = $sp->lambdify($x, $sp->sympify('x**2 + 1'));
$f(3);                                                   // 10
```

* **SymPy numbers are proxies**, not PHP numbers: `Integer`, `Rational` and `Float` are SymPy classes, not Python `int`/`float`. Convert with `$py->functions->int($n)`, `$py->functions->float($n)` or `(string) $n` (exact: `'5/4'`).
* Build expressions from strings with `sympify()`, or use method forms: there are no operators on proxies, so `x**2` is `$x->__pow__(2)`.
* `solve()` for a system returns a dict keyed by `Symbol`s. Those can't be PHP array keys, so the dict stays a proxy; convert in Python: `$py->eval("{str(k): v for k, v in ...}")`.
* `lambdify()` returns a plain Python function: call it from PHP like a closure.
* Big symbolic computations can take seconds; raise `timeout` if needed.

### spaCy (natural language processing)

```php
$spacy = $py->import('spacy');
$nlp = $spacy->load('en_core_web_sm');                  // python -m spacy download en_core_web_sm
$doc = $nlp('Tim Cook visited Ramallah on Monday.');

foreach ($doc->ents as $e) { echo $e->text, ' ', $e->label_; }   // Tim Cook PERSON, Ramallah GPE, ...
$tokens = $doc->to_json()['tokens'];                    // every token, one round trip
```

* **Load the model once** and reuse `$nlp`: `import spacy` takes ~0.5 s and loading the small model ~0.3 s; each `$nlp($text)` is then ~10 ms. Under PHP-FPM every request pays the load, so use spaCy from CLI scripts, queue workers or long-running PHP.
* **Iterating tokens costs one round trip per attribute read.** For whole documents use `$doc->to_json()` (text, entities, sentences and every token's lemma/POS/tag/dep as plain arrays) or compute what you need in Python with `eval()`.
* `spacy.blank('en')` gives a tokenizer without downloading a model.
* Matcher patterns are plain PHP arrays: `[[['LIKE_NUM' => true], ['LOWER' => 'billion']]]`. Match ids are 64-bit unsigned hashes, larger than PHP ints, so they arrive as proxies; pass `as_spans: true` to get spans with `->label_` instead.
* `$nlp->pipe($texts)` processes many texts in batches; iterate it lazily from PHP.
* A PHP closure can be a pipeline component (register it with `Language.component(...)` in `exec`, then `$nlp->add_pipe(...)`). To store results on the doc, use a custom extension and write `$doc->_->name = ...`, or write into `user_data` through `Py::ref($doc, 'user_data')['k'] = ...`. Plain `$doc->user_data['k'] = ...` changes nothing in Python: `user_data` is a plain dict, so PHP gets a **copy**.
* `$spacy->displacy->render($doc, style: 'ent')` returns HTML you can put straight into a page.

Other NLP libraries (NLTK, Hugging Face `transformers`, `sentence-transformers`, `langdetect`, …) follow the same pattern; the main thing to watch is load time, so keep one session alive and load models once.

## More libraries in practice

The same pattern covers most of Python: import, call through proxies, copy plain results into PHP. Examples 08–11 show four common cases; each has an optional test suite (`StandardLibraryTest`, `MatplotlibTest`, `PillowTest`, `ScikitLearnTest`) that skips when the library isn't installed.

**Standard library** (no installs): `difflib` for "did you mean" suggestions and text diffs, `statistics` (median, quantiles, correlation), `itertools` combinations, exact `fractions.Fraction` and `decimal.Decimal`, `collections.Counter`, `ipaddress` networks.

* Generators and iterators (`itertools.product`, `difflib.unified_diff`) come back as proxies; wrap them in `$py->functions->list(...)` to get a PHP array, or `foreach` over them.
* `Fraction` and `Decimal` stay proxies so they stay exact; print them or convert with `float()`/`str()`. There are no operators on proxies, so add with `->__add__()` or in `eval()`.

**Charts with matplotlib:**

```php
$py->import('matplotlib')->use('Agg');           // no screen on a server: set BEFORE importing pyplot
$plt = $py->import('matplotlib.pyplot', as: 'plt');
[$fig, $ax] = $plt->subplots(figsize: Py::tuple(8, 4));
$ax->bar($months, $revenue);
$fig->savefig('chart.png', dpi: 150);
$plt->close($fig);                               // free the figure in long-running PHP
```

* For a web page, render SVG into `io.StringIO` and echo the string; for an HTTP image response, render PNG into `io.BytesIO`: Python `bytes` arrive in PHP as a binary string.

**Images with Pillow:**

* `$Image->open($path)`, `->thumbnail(Py::tuple(400, 400))` (in place, keeps the aspect ratio), `ImageOps::fit()` for square crops, `->save($file, quality: 85)`.
* Image data moves both ways as strings: `$buffer->getvalue()` gives PHP the bytes, and `$Image->open($io->BytesIO($phpString))` reads an upload without a temporary file. PHP strings that aren't valid UTF-8 are sent as `bytes`, which is what binary image data always is.
* Call `ImageOps.exif_transpose()` on phone photos so they aren't sideways.

**Machine learning with scikit-learn:**

* PHP arrays of rows are valid training data: `$model->fit($rows, $labels)`. Results are NumPy arrays; call `->tolist()` to copy them into PHP.
* `train_test_split()` returns four lists that PHP can destructure: `[$xTrain, $xTest, $yTrain, $yTest] = ...`.
* Train once and save with `joblib.dump()`; later requests `joblib.load()` the file instead of retraining. Loading is much cheaper than training, but still costs time: reuse one session where you can.

Other popular libraries (requests/httpx, BeautifulSoup, openpyxl, OpenCV, PyTorch, Hugging Face `transformers`) follow the same rules. Watch out for import and model-loading time, keep heavy objects in one long-lived session, and copy only the results PHP needs.

---

← [PHP callbacks](09-php-callbacks.md) · [Contents](README.md) · [Output and errors](11-output-and-errors.md) →
