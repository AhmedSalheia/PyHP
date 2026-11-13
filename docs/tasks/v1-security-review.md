# v1.0 security review: the worker and its socket

Reviewed on develop after PR #44 (2026-11-09): `src/Internal/Worker.php`, `src/Internal/Codec.php`, `src/Session.php` and `python/pyhp_worker.py` (start-up, transport, handshake, request handling).

## Threat model

- **Trusted:** the PHP application, the Python code it runs (`exec`, `execFile`, imported libraries), and the user account both run as. Code running as that account can already read the worker's memory and environment, so it's out of scope.
- **Untrusted:** data the application passes through PyHP (form input, uploaded files, API payloads); **other local users and processes** on the same machine (shared hosting, multi-user servers, containers sharing a network namespace); anything on the network.
- **Goal:** untrusted data can't become code, other local users can't talk to or impersonate the worker, and nothing is reachable from the network.

## What holds

| Area | Finding |
|---|---|
| Network exposure | The worker binds `127.0.0.1` only, on a random port, and closes the listener as soon as PHP has authenticated. Nothing listens on other interfaces. |
| Authentication | A 128-bit random token (`random_bytes(16)`) passed in the environment, never on the command line. The first frame must carry it, compared in constant time (`hmac.compare_digest`); other connections are dropped without a reply. The worker's `hello` echoes the token and PHP checks it with `hash_equals`, so PHP knows it reached a process that knows the secret. |
| Secrets in the environment | `PYHP_*` variables are removed from `os.environ` before user code runs, so child processes don't inherit them. `/proc/<pid>/environ` still shows them, but only to the same user (in scope only for trusted code). |
| Temporary files | The port file and the default log are created with `tempnam()`: unique names, mode 0600 on Unix, the per-user temp folder on Windows. The port file is deleted right after connecting; the log on close. |
| Frame limits | Both sides check the announced length against `max_message_bytes` before reading; the authentication frame is capped at 4,096 bytes; PHP caps the `hello` frame at 64 KiB. Nothing allocates based on an unchecked length. |
| Malformed frames | Invalid JSON, non-objects and oversized frames end the session cleanly (worker exit code 2, `ProtocolException` in PHP); PHP kills the worker after any protocol error. |
| Values from untrusted input | Data crosses as JSON scalars, lists and dicts, base64 bytes and tagged values. There is no pickle, `eval` or object construction from data; `bytes` are decoded with `validate=True`; nesting is limited to 256 levels on both sides. Proxies refuse `serialize()`/`unserialize()`, so a proxy id can't be smuggled through PHP serialization. |
| Process start | `proc_open()` with an argument array: no shell, so no shell injection through the interpreter path or options. stdin is the null device. |
| Timeouts | One deadline per call; on expiry the worker is killed (`SIGKILL` / `TerminateProcess`), not asked. |

## Findings and fixes

| # | Severity | Finding | Status |
|---|---|---|---|
| 1 | Medium | **The working directory was first on `sys.path`** (like `python -c`). When PHP's working directory holds files other people can write (an uploads folder, a shared web root), a file named after any module (`random.py`, `json.py`) would run as code on the next import, by PyHP or by any library. | ✅ Fixed: neither the working directory nor the worker's folder is on `sys.path`; only Python's own paths and the `paths` option. Breaking, with an upgrade note. |
| 2 | Low | **Windows port sharing.** Python's `create_server()` doesn't set `SO_EXCLUSIVEADDRUSE`, so on Windows another process of the same user could bind the same port and take over new connections. | ✅ Fixed: the listener sets `SO_EXCLUSIVEADDRUSE` on Windows. |
| 3 | Low | **Start-up stalling.** Each unauthenticated connection could hold the single-threaded accept loop for up to 5 s, so a local process connecting repeatedly could delay start-up until `startup_timeout`. | ✅ Mitigated: 2 s per connection. A determined local attacker can still delay start-up (denial of service only); they can never get in. |
| 4 | Info | **Names are code.** Function, class, module, attribute and method names, and `execFile()` paths, choose what runs. An application passing user input as a name (`$py->functions->{$_GET['fn']}(...)`) lets visitors call `eval`. This is how the API works, not a bug. | ✅ Documented in [Deployment and security](../13-deployment-and-security.md#security). |
| 5 | Info | **Tracebacks and the log can contain data**: values in exception messages, file paths. | ✅ Documented: log errors, don't show them to visitors; keep a custom `log_file` out of the web root. |
| 6 | Info | **Child processes outlive the worker.** Processes your Python code starts aren't killed with it on a timeout. | Known limitation, already in [Limitations](../14-limitations.md). Killing the process group needs a POSIX-only PHP extension; revisit after 1.0 if needed. |
| 7 | Info | **The handshake doesn't stop a relay.** A process that could take over the port (only possible as the same user, or before fix 2 on Windows) could relay frames between PHP and the real worker. Stronger handshakes don't help against a relay without channel binding. | Accepted: same-user processes are trusted by the threat model, and fix 2 closes the Windows gap. |

## Not in scope

- Sandboxing Python code: PyHP runs trusted code with the user's full permissions, by design. Use OS-level isolation (containers, separate users) to contain untrusted Python.
- Supply-chain risks in Python packages installed by `pyhp setup`: the same as any `pip install`; `pyhp.lock` pins versions.
