# Study: PHP callbacks (Python calling PHP functions)

| | |
|---|---|
| Status | Feasibility study, not implemented |
| Date | 2026-11-07 |
| Applies to | PyHP after protocol 1 (imports, `modules`, `classes`, table export) |
| Verdict | **Feasible.** Medium–high complexity, contained to the transport layer. Recommended, with clear limits (same call, same thread, no async). |

---

## 1. The question

Today PyHP only goes one way: PHP calls Python. Many Python APIs expect a *function* as an argument and call it themselves:

```php
// What users will want to write:
$df['name']->apply(fn (string $s) => ucfirst($s));                 // pandas
$py->functions->sorted($people, key: fn (array $p) => $p['age']);    // builtins
$py->modules->scipy->optimize->minimize(fn ($x) => cost($x), x0: [1, 2]);
$model->fit($x, $y, callbacks: [fn ($epoch, $logs) => logProgress($epoch, $logs)]);
```

Each of these needs Python, in the middle of handling one PHP request, to call PHP code, get the result, and continue. Today that's impossible: passing a `Closure` throws `ConversionException`.

**Can PyHP support this without embedding, extensions, or making the rest of PyHP fragile?**

## 2. Short answer

Yes. The key observation: **while Python runs a PHP request, PHP is idle, blocked in `Worker::roundTrip()` waiting for the response.** So Python can send a *callback request* over the same socket instead of a response. PHP runs the closure, sends the result back, and goes back to waiting. Python continues. No threads, no second connection, no PHP extension.

The same idea works recursively: inside a callback, PHP may call Python again (a *nested request*), and Python may call PHP again inside that, up to a depth limit. Both sides are always "one side computes, the other waits", so the conversation never becomes concurrent.

What makes it **medium–high** complexity isn't the core idea. It's the edges: errors crossing the boundary twice, timeouts, threads, callbacks kept after the call returns, and object lifetimes.

## 3. How it would work

### 3.1 Today

```
PHP                                  Python
 │── request {op: call_method} ───────▶│
 │        (PHP waits)                  │ runs df.apply(...)
 │◀────────────────────── response ────│
```

### 3.2 With callbacks

```
PHP                                            Python
 │── request {op: call_method, apply, [cb#3]} ──▶│
 │        (PHP waits in roundTrip)               │ df.apply(PhpCallable#3) runs …
 │◀── {op: php_call, cb: 3, seq: 1, args: ["ab"]}│   … and calls the PHP function
 │ runs $closure("ab")                           │        (Python waits)
 │── {op: php_return, seq: 1, value: "Ab"} ─────▶│
 │        (PHP waits again)                      │ … continues, next row …
 │◀── {op: php_call, cb: 3, seq: 2, ...} ────────│
 │   …                                           │
 │◀────────────────────────── response ──────────│ apply finished
```

### 3.3 Re-entrancy (a callback that calls Python)

```
PHP                                     Python
 │── request A ─────────────────────────▶│
 │◀──────────────── php_call (seq 1) ────│  inside A
 │  closure runs $py->eval('x + 1') …    │
 │── request B (nested) ────────────────▶│  Python, still inside A's call,
 │◀───────────────────── response B ─────│  serves B, then keeps waiting
 │── php_return (seq 1) ────────────────▶│
 │◀───────────────────── response A ─────│
```

Both sides keep a **stack** of what they're waiting for. Each side, while waiting, handles whatever frame arrives:

| Side waiting for… | Frame that arrives | What it does |
|---|---|---|
| PHP waiting for a response | `response` | done: return it |
| | `php_call` | run the closure, send `php_return`, keep waiting |
| Python waiting for a `php_return` | `php_return` with the expected `seq` | done: return or raise |
| | a normal request (nested) | handle it, send its response, keep waiting |

The frame formats stay the same (length + JSON). Only new `op` values are added, and the protocol version goes from 1 to 2, which the handshake already checks.

## 4. Design decisions in detail

### 4.1 Which PHP values become Python callables

**Only `Closure` objects**, including first-class callable syntax (`strlen(...)`, `$obj->method(...)`), which PHP turns into closures.

Strings and arrays are **never** treated as callables, even if `is_callable()` says yes (`'system'`, `[$obj, 'method']`). Otherwise passing the string `"system"` as data would silently hand Python a way to call `system()`. Strings stay strings. That's a security property, not just a convenience.

A closure is sent as `{"$t": "callback", "id": 3}`. PHP keeps `id → Closure` in a per-session registry. Python wraps the id in a `PhpCallable` object with `__call__`.

### 4.2 Arguments and return values

They use the existing conversion rules in both directions:

* Python → PHP arguments: plain data is copied, everything else arrives as a `PyObject` proxy (e.g. a pandas `Series` passed to a `pipe()` callback).
* PHP → Python return value: scalars, arrays, proxies, `Py::tuple()` etc., the same as any argument PHP sends today.
* Python keyword arguments become PHP named arguments (`$closure(...$args, ...$kwargs)`). Unknown names raise PHP's own `Error`, which travels back as an exception (4.3).

### 4.3 Errors crossing the boundary

Each direction needs care:

1. **PHP closure throws.** PHP sends `php_return` with `ok: false` (class, message, file:line). Python raises `PhpError` at the call site, so Python code can catch it like any exception.
2. **Python doesn't catch it.** The original PHP request fails. PHP sees the Python error. If it is the `PhpError` from that same callback, PHP **rethrows the original `Throwable` object**, with the Python traceback attached as context, so `catch (MyDomainException $e)` keeps working across the round trip. Implementation: PHP keeps the thrown object in a map keyed by `seq` until the outer request finishes.
3. **Python fails inside a nested request (B).** That's an ordinary `PythonException` thrown inside the closure. If the closure doesn't catch it, case 1 applies.

### 4.4 Timeouts

The `timeout` option exists to stop *Python* that hangs. Time spent running PHP callbacks should **not** count against it: a PHP closure that takes a while doesn't mean the worker is stuck, and PHP has `max_execution_time` for its own code. So the deadline pauses while PHP runs a callback and resumes when it sends `php_return`.

The alternative, counting everything, is simpler but surprising. Ten thousand fast callbacks could kill a healthy worker. Open question Q2.

### 4.5 Threads

`PhpCallable.__call__` is only allowed **on the thread that is serving the current PHP request, while that request is active**. Anything else raises `RuntimeError` with a clear message:

* a callback stored and called after the request returned (event handlers, `atexit`),
* a callback called from another thread (`ThreadPoolExecutor`, a library's background thread).

Otherwise two threads could write frames at the same time and corrupt the conversation. Libraries that call callbacks from worker threads (some ML training loops, parallel `apply` engines) won't be supported in the first version. This is the biggest functional limit.

### 4.6 Lifetime and memory

* The PHP registry holds the closure (and everything it captures, e.g. `$this`) for as long as Python holds the `PhpCallable`.
* When Python drops it (garbage collection), a `weakref.finalize` queues the id, which is sent as `"release": [...]` with the next response. This mirrors how proxies are released today, in the opposite direction.
* Closing the session drops all callbacks.
* The same closure passed twice gets the same id (PHP keys the registry by object identity using `WeakMap` or `spl_object_id`), so it isn't registered twice.

### 4.7 Nesting depth

Each nested level uses stack on both sides (PHP frames and Python frames). Limit the depth to e.g. **32** levels: deeper nesting throws `CallbackDepthException` in PHP and `RecursionError` in Python. That's plenty for real code and stops runaway recursion.

### 4.8 Output

`print()` output from Python during a call is delivered when the call finishes (unchanged). Output that PHP code produces inside a callback (`echo`) goes to PHP's output as usual, so it may appear *before* Python output printed earlier in the same call. Document this; don't fix it.

## 5. Performance

A callback costs about one PyHP round trip: **~70–100 µs** (a PHP→Python function call measured 72 µs on Linux, 87 µs on Windows), plus the closure's own time.

| Use | Callbacks | Overhead |
|---|---|---|
| `minimize()` objective, 200 iterations | 200 | ~20 ms: fine |
| `sorted(key=…)` on 10,000 items | 10,000 | ~1 s: noticeable |
| `$df['x']->apply(fn)` on 1,000,000 rows | 1,000,000 | ~90 s: **too slow** |

That's inherent to any out-of-process design. The README will steer users to **vectorized callbacks**: functions that receive a whole column and use pandas methods on it, so it's one round trip instead of one per row:

```php
// 1 callback instead of 1,000,000:
$df['score'] = $df['score']->pipe(fn (PyObject $s) => $s->mul(1.1)->round(2));
```

A later helper, e.g. `Py::vectorize($fn)`, could run a row-wise PHP function once per batch of rows. That's out of scope for the first version.

## 6. What changes in the code

| Area | Change |
|---|---|
| Protocol | Version 2. New ops `php_call`, `php_return`; new value tag `callback`; `release` for callbacks in responses. Documented in a protocol spec file. |
| `python/pyhp_worker.py` | Turn the transport into a `Connection` object with one `wait_for(predicate)` loop used by both the main loop and `PhpCallable.__call__`. Add `PhpCallable` (thread/active-request checks, `weakref.finalize` release), `PhpError`, nesting depth counter. |
| `src/Internal/Worker.php` | `roundTrip()` becomes a loop that handles `php_call` frames while waiting for its response, pausing the deadline during callbacks. |
| `src/Internal/Codec.php` | `Closure` → `{"$t": "callback"}` instead of `ConversionException`; callback registry; decode `release` for callbacks. |
| `src/Session.php` | Callback registry ownership, depth counter, rethrowing the original `Throwable` (4.3). |
| New exceptions | `CallbackDepthException` (PHP), `PhpError` (Python). |
| Docs | README section "Callbacks", limitations list, performance table. |

Everything else (vars, functions, classes, modules, table export, proxies) keeps working unchanged. Callbacks are just a new value type plus a smarter wait loop.

## 7. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Frame interleaving bugs (deadlocks, a response read by the wrong waiter) | Medium | Strict stack discipline: each side only ever waits for one thing at a time, enforced with assertions and `seq` numbers. Property-style tests with random nesting. |
| Libraries calling callbacks from other threads | High for some ML/parallel libs | Clear `RuntimeError` (4.5). Document. A later "thread-safe mode" could serialize calls with a lock and a frame dispatcher thread, at a large complexity cost. |
| Users writing per-row callbacks on big data and finding them slow | High | Docs, the vectorized `pipe()` pattern, a measured performance table. |
| Memory held by forgotten callbacks | Low | Releases tied to Python GC, all cleared on `close()`. |
| Exceptions losing their identity across two hops | Medium | 4.3 design plus tests asserting `catch (SpecificException)` works. |
| Windows-specific socket behaviour | Low | Same socket and framing as today; covered by the Windows CI job. |

## 8. Alternatives considered

| Alternative | Why not |
|---|---|
| A second socket only for callbacks | Two channels to keep in sync, still needs ordering rules. Doesn't remove any of the hard parts. |
| A thread in Python that dispatches callbacks | Needed only for multi-threaded callers; adds locking and shutdown complexity. Kept as a possible later "thread-safe mode". |
| PHP Fibers | PHP is already purely synchronous here; fibers add nothing for version 1. Could matter later for async PHP runtimes (Swoole, ReactPHP). |
| Shipping PHP code to Python to run there | Not possible: Python can't run PHP. |
| Only allowing Python-side lambdas (`$py->exec('f = lambda x: ...')`) | Already possible today; doesn't let PHP logic (your domain code, DB access, Laravel services) participate. |

## 9. Suggested integration plan

Each phase is a separate `feature/` branch with tests written first, mergeable on its own, and leaves PyHP fully working.

### Phase 0 — Protocol spec (½ day)

* Write `docs/PROTOCOL.md`: every frame and op of protocol 1 as it is today, then the protocol 2 additions from this study.
* Decide the open questions (section 10).
* **Exit:** spec reviewed by Ahmed.

### Phase 1 — Transport refactor, no new behaviour (1 day)

* Python: introduce `Connection` with `send()` and `wait_for()`. The main loop uses it.
* PHP: `Worker::roundTrip()` gets the same loop structure (still only expects responses).
* **Exit:** all existing tests pass unchanged on Linux and Windows; benchmarks unchanged within noise.

### Phase 2 — Closures as values (1 day)

* PHP: `Closure` → `{"$t": "callback", "id"}`, registry, identity reuse, release handling, cleanup on close.
* Python: `PhpCallable` objects that raise `NotImplementedError("callbacks arrive in phase 3")` when called; `weakref.finalize` release.
* **Exit:** tests for registration, reuse, release on Python GC, cleanup on close, and strings/arrays never becoming callables.

### Phase 3 — Synchronous callbacks (2 days)

* `php_call` / `php_return` handling on both sides; argument and result conversion; keyword arguments.
* Errors in both directions, including rethrowing the original `Throwable` (4.3).
* Deadline pauses during callbacks (or not, per Q2).
* **Exit:** tests for `sorted(key=…)`, `map`, `$series->apply(fn)`, `pipe()` with a proxy argument, `scipy.optimize.minimize` (optional test), an exception thrown in PHP and caught in Python, one surfacing in PHP with its original class, and timeout behaviour.

### Phase 4 — Re-entrancy (1–2 days)

* Python serves nested requests while waiting for `php_return`; PHP handles `php_call` frames inside nested requests.
* Depth counter and `CallbackDepthException`.
* **Exit:** tests for a callback that reads `$py->vars`, calls `$py->functions`, and creates proxies; three-level nesting; depth limit; a proxy created inside a callback and used after it returns.

### Phase 5 — Guards (1 day)

* Thread check and active-request check in `PhpCallable.__call__`.
* **Exit:** tests for calling a callback from `threading.Thread` and after the request returned (both clear `RuntimeError`s), and a worker that keeps working afterwards.

### Phase 6 — Docs and measurements (½ day)

* README section, limitations, performance table measured on Linux and Windows, a callback example in `examples/`.
* **Exit:** README reviewed; CHANGELOG entry.

**Total: about 6–8 working days**, roughly the size of the MVP's transport and proxy work combined.

## 10. Open questions for Ahmed

1. **Callable types:** only `Closure` (recommended, see 4.1), or also `'strlen'` / `[$obj, 'method']` strings and arrays behind an explicit wrapper like `Py::callback('strlen')`?
2. **Timeouts:** pause the deadline while PHP runs a callback (recommended), or count all time?
3. **Exceptions:** rethrow the original PHP exception when it surfaces through Python (recommended), or always wrap it in `PythonException`?
4. **Nesting depth:** 32 levels enough?
5. **Threads:** is "same thread, during the call" acceptable for version 1, given it rules out some ML libraries' background-thread callbacks?
6. **Priority:** build it right after the code review and before the first release, or after 0.1.0?
