# 6. Imports

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

← [API reference](05-api-reference.md) · [Contents](README.md) · [How values are converted](07-value-conversion.md) →
