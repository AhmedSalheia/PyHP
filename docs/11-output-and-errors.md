# 11. Output and errors

## Printed output and the log

Python has two kinds of output, and PyHP treats them differently.

**Text printed to `sys.stdout`** (`print()`) during a call goes, depending on the session:

* `OutputMode::Echo` (default): `echo`ed into PHP's output **live**, about a line at a time while the call runs, so a long job's progress shows up as it happens in the CLI (or the HTTP response).
* `OutputMode::Capture`: kept in a buffer and read with `$py->takeOutput()` after the call.
* **`$py->onOutput(fn (string $text) => ...)`**: sent to your callback live, chunk by chunk, instead of echoing or capturing. Use it for progress bars, logs or pushing progress to a browser. `$py->onOutput(null)` goes back to the output mode.

```php
$py->onOutput(fn (string $text) => $logger->info(rtrim($text)));
$py->exec('for i in range(3): print(f"epoch {i} done")');   // three log lines, as they happen
```

Chunks are sent when Python prints a newline or flushes (`print(..., flush=True)` for a progress counter on one line); text without a newline arrives at the latest when the call ends. Output printed before a Python exception is delivered too. If an `onOutput` callback throws, the Python call still runs to the end and the exception is rethrown when it returns. Time spent in the callback doesn't count against `timeout`.

**Everything else goes to the worker log:** `sys.stderr`, `warnings`, logging handlers that write to stderr, output from C extensions writing directly to file descriptor 1 or 2, and output from background threads that print *between* calls. Read it with `$py->log()`. By default the log is a temp file deleted on `close()`. Set `log_file` to keep it.

Limits:

* Only the thread that serves PHP sends live output. What background threads print during a call is sent with the next chunk from the main thread, or when the call ends.
* **`input()` raises `EOFError`.** The worker has no stdin. Pass data in through `vars` or arguments.
* Captured output counts toward `max_message_bytes`.

## Errors

Everything PyHP throws extends `PyHP\Exception\PyHPException`.

| Exception | When | Session afterwards |
|---|---|---|
| `PythonException` | Your Python code raised. Has `pythonType` (`"ValueError"`), `pythonMessage`, and `pythonTraceback` (the full Python traceback, starting at your code). `getMessage()` is `"ValueError: message"`. | ✅ still usable |
| `ConversionException` | A value can't cross the boundary (`DateTime`, other objects, proxy from another session, strict `toPhp()` failure). | ✅ still usable |
| *your own exception* | A [PHP callback](09-php-callbacks.md#php-callbacks) threw and Python didn't catch it: the original object is rethrown. | ✅ still usable |
| `MessageTooLargeException` | A request or result is bigger than `max_message_bytes`. | ✅ still usable |
| `ConfigException` | Invalid option (unknown key, wrong type, bad value). | n/a: thrown before starting |
| `WorkerStartException` | Python couldn't be started: interpreter not found, crashed during start-up (e.g. Python < 3.10), didn't connect within `startup_timeout`. The message includes the exit code and the worker log. | n/a: never started |
| `TimeoutException` | One call took longer than `timeout`. **The worker is killed** because a running Python call can't be interrupted safely. | ❌ closed, all state lost; with `auto_restart`, the next call gets a fresh worker |
| `WorkerCrashedException` | The worker process died: `os._exit()`, a segfault in a native library, killed by the OS (out of memory). The message includes the log tail. | ❌ closed; with `auto_restart`, the next call gets a fresh worker |
| `SessionClosedException` | You used a session, or a proxy, after `close()`, or after a crash or timeout without the `auto_restart` option. | ❌ closed |
| `StaleProxyException` | You used a proxy from before the session restarted its worker (crash, timeout or `restart()`): the object no longer exists. | ✅ still usable; get the object again |
| `ProtocolException` | PHP and the worker disagree about the wire format, usually because the PHP and Python halves of PyHP come from different versions. | ❌ closed |

Notes:

* `sys.exit()` in your code does **not** stop the worker. It's reported as `PythonException` with type `SystemExit`. `os._exit()` does stop it, as `WorkerCrashedException`.
* `KeyboardInterrupt` and other `BaseException`s raised by your code are reported as `PythonException` too.
* There is **no automatic restart or retry.** If a session dies, start a new one and rebuild the state you need, because retrying could repeat side effects.
* After a crash or timeout the default session (`Py::default()`) starts a fresh worker on next use, **with empty state**.

```php
use PyHP\Exception\{PythonException, TimeoutException, PyHPException};

try {
    $py->functions->risky($input);
} catch (PythonException $e) {
    error_log($e->pythonTraceback);           // your bug, session still fine
} catch (TimeoutException $e) {
    $py = Py::session();                       // worker was killed: start over
} catch (PyHPException $e) {
    // any other PyHP problem
}
```

---

← [Using other Python libraries](10-libraries.md) · [Contents](README.md) · [Memory and performance](12-memory-and-performance.md) →
