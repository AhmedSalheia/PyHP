# 14. Limitations

## Every limitation in one list

**Execution model**

1. Python runs in a separate process. Nothing is shared in memory; values are copied through JSON, or referenced by ID.
2. One call at a time per session. Not thread-safe; don't share sessions across forks.
3. Every proxy operation is a round trip (~0.1 ms on Linux).
4. Sessions can't outlive their PHP process. Under PHP-FPM, every request pays start-up and import time.
5. Timeouts kill the worker and lose all session state. Running Python calls can't be interrupted gently. With the `auto_restart` option the next call gets a fresh worker and your `onStart` hooks rebuild the state, but the call that timed out still fails.
6. Child processes started by your Python code are not killed with the worker.
7. No automatic retry: after a crash or timeout the failed call throws; only the next call restarts the worker (`auto_restart` option).

**Syntax gaps**

8. No Python operators on proxies (`+`, `>`, `&`, `~`, `@`, …). Use method forms or `eval()`.
9. PHP callbacks: one round trip each (~65 µs), only from the thread serving PHP and while PHP waits, at most 32 levels of nesting. Strings/arrays are never callables. See [PHP callbacks](09-php-callbacks.md#php-callbacks).
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

22. Live output arrives per line (or per explicit flush), not per character; text printed by background threads during a call waits for the main thread's next line.
23. `input()` can't read anything.
24. Output from background threads between calls goes to the log, not to PHP.

**Environment**

25. The optional library suites (SymPy, spaCy, matplotlib, Pillow, scikit-learn) run in CI on Linux only; pandas and NumPy run on every system; other libraries aren't tested by PyHP at all (see [Platform support](13-deployment-and-security.md#platform-support)).
26. 64-bit PHP 8.2+ and Python 3.11+ only.
27. Not a sandbox. Trusted code only.
28. `multiprocessing` from session code is untested. Functions defined inside the session (rather than in importable modules) can't be pickled into child processes on Windows/macOS (`spawn`).

---

← [Deployment, security and platforms](13-deployment-and-security.md) · [Contents](README.md) · [How PyHP works inside](15-internals.md) →
