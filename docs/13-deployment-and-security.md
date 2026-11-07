# 13. Deployment, security and platforms

## Where you can use it

| Environment | Works? | What to know |
|---|---|---|
| **CLI scripts, cron jobs, artisan commands** | ✅ Best fit | One session for the whole script. |
| **Queue workers, daemons, Octane/RoadRunner/FrankenPHP workers, NativePHP** | ✅ with care | Sessions persist across jobs/requests in the same PHP process, which avoids start-up cost but **shares Python state between jobs/requests**. Prefer explicit `Py::session()` objects with a clear owner over `Py::default()`. Call `Py::reset()` between units of work if they must not share state. |
| **PHP-FPM / Apache mod_php (classic web requests)** | ✅ but slow to start | Every request starts its own worker (~40 ms plus your imports, e.g. +200 ms for pandas). Workers can't be kept alive between requests in this model. Fine for occasional reports, not for hot endpoints. |
| **Concurrency** | ⚠️ | A session handles one call at a time and isn't thread-safe. Don't share one session between threads, fibers that interleave calls, or forked processes. |
| **`pcntl_fork()` with an open session** | ❌ | Parent and child would share one socket. Open sessions after forking. |
| **Shared hosting** | ⚠️ | Works only if `proc_open` is allowed and you can run Python there. |

## Security

* **Only run code you trust.** Python code run through PyHP can do anything your PHP process's user can do: read files, open network connections, start processes. A timeout doesn't contain it.
* **Never build Python source from user input.** Pass data as values, not code:

  ```php
  // ✗ Injection: user input becomes code
  $py->exec("name = '{$_GET['name']}'");

  // ✓ User input stays data
  $py->vars->name = $_GET['name'];
  $py->functions->process($_GET['name']);
  ```

* The worker listens on `127.0.0.1` only (never on the network), on a random port, and only until PHP has connected; then it closes the listener. The first message on a connection must contain a random 128-bit token that only the PHP process that started the worker knows. Any other connection is dropped without a reply, and a worker that nobody connects to within `startup_timeout` exits. Local processes running as **your own user** could still inspect the worker (environment variables, memory), as with any process.
* The token and PyHP's own variables (`PYHP_*`) are removed from `os.environ` before your code runs.
* PyHP never logs your code, arguments, or variables. The worker log contains only what Python writes to stderr.

## Platform support

| Platform | Status |
|---|---|
| **Linux** | ✅ Tested in CI: PHP 8.2 and 8.4, Python 3.10 and 3.13, with pandas, NumPy, SymPy and spaCy. |
| **Windows** | ✅ Tested in CI (PHP 8.2 and 8.4, Python 3.10 and 3.13, pandas and NumPy). It uses sockets instead of pipes (PHP can't wait on pipes with a timeout on Windows), no shell (an argument array goes to `proc_open`), `NUL` instead of `/dev/null`, and passes `SYSTEMROOT` through. |
| **macOS** | ✅ Tested in CI (PHP 8.2 and 8.4, Python 3.10 and 3.13, pandas and NumPy). |
| **32-bit PHP** | ❌ Not supported (PHP integers must be 64-bit). |
| **Python < 3.10** | ❌ The worker fails at start-up with a clear `WorkerStartException`. |

Process trees: when a worker is killed (timeout, `close()` after the grace period), **child processes your Python code started are not killed**, on any platform.

---

← [Memory and performance](12-memory-and-performance.md) · [Contents](README.md) · [Limitations](14-limitations.md) →
