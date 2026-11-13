# PyHP Roadmap

Goal: make Python's libraries (data analysis, science, NLP, machine learning, and everything else) usable from PHP, in PHP syntax, with no compiled extension, on Linux, Windows and macOS, for PHP developers who don't need to know Python well.

| # | Scope | Version | Status | Summary |
|---|---|---|---|---|
| 1 | Foundation | v0.1.0 | released 2026-11-07 | Worker, sessions, proxies, `vars`/`functions`/`classes`/`modules`, Python-style imports, PHP callbacks, fast table export, CI on three systems, MIT license. |
| 2 | New home | v0.1.1 | released 2026-11-07 | Package renamed to `pyhp/pyhp`, repository moved to `pyhp-php/pyhp`. |
| 3 | Tooling | v0.2.0 | released 2026-11-07 | `pyhp setup` (uv or venv/pip), `pyhp info`, automatic `.venv` detection, `Py::ref()`, examples 06–11, numbered docs. |
| 4 | Ecosystem and data fidelity | v0.3.0 | released 2026-11-08 | Python requirements declared by any Composer package, exact environments, lossless numbers, real-world setup tests. |
| 5 | Long-running PHP | v0.4.0 | released 2026-11-09 | Worker recovery, shared sessions for queue workers and app servers, live output, faster callbacks. |
| 6 | Stable | v1.0.0 | in progress | Reviewed and frozen public API and protocol, a written compatibility promise, quality bars, a docs website; release candidates first. |
| 7 | Laravel | pyhp/laravel v0.1.0 | planned (after 1.0) | A separate package built on a stable PyHP: service provider, config, facade, artisan commands. |

## v0.3.0: Ecosystem and data fidelity

PyHP becomes something other packages can build on, and values cross the bridge with fewer surprises.

1. **Python requirements from every Composer package.** A package built on PyHP (a Laravel integration, a package that wraps spaCy, ...) declares its own `extra.pyhp.requirements`. `pyhp setup` collects them from all installed packages (`vendor/composer/installed.json`), merges them with the root project's, and installs everything into one environment.
   - The root project decides: its own requirements are always added (so it can tighten versions), `extra.pyhp.ignore-packages` skips a Composer package's Python requirements, and `extra.pyhp.overrides` replaces a version rule outright (uv only; pip has no overrides).
   - Conflicting requirements (two packages asking for incompatible versions) are reported clearly, naming the packages, instead of failing inside pip.
   - `pyhp info` shows which package asked for each requirement.
2. **Exact environments.** `pyhp setup --exact` makes the venv match the requirements exactly, removing packages that are no longer listed (`uv pip sync` with uv). An optional lock file (`pyhp.lock`) records exact versions so every machine and CI run gets the same environment.
3. **Lossless numbers.** Integer-like objects that aren't Python `int` (SymPy `Integer`, NumPy and pandas nullable integers) become PHP `int` when they fit in 64 bits, and stay proxies otherwise. Removes most `$py->functions->int()` calls.
4. **Real-world setup tests in CI.** A job on Linux, Windows and macOS runs `pyhp setup` (with uv, and with `--no-uv`) in a small sample project, then checks that a session uses the new venv and that `pyhp info` exits 0.
5. **An official starter project.** The `pyhp-sample` project, polished into a template people can copy: `composer.json` with `extra.pyhp`, a small service class, tests.

## v0.4.0: Long-running PHP

PyHP becomes comfortable in production setups where PHP processes live for a long time.

1. **Worker recovery.** An opt-in `restart` option: after a crash or timeout the failed call still throws, and the next call starts a fresh worker and runs the `onStart` hooks that rebuild session state (imports, loaded models). `Session::restart()` and `Session::ping()`.
2. **Session reuse.** `Py::shared($name, $setup)`: one self-healing session per process and name for queue workers, Laravel Octane, RoadRunner and FrankenPHP, so expensive imports and models load once per process instead of once per job. A session pool can follow if someone needs it.
3. **Live output.** Optionally stream what Python prints to PHP while a call runs (a callback), instead of only when it ends. Useful for long jobs and progress bars.
4. **Faster callbacks.** `Py::vectorize()`: a PHP closure applied to a whole column or array in batches, one round trip per batch instead of per value.
`pyhp/laravel` moved to its own release right after 0.4.0 (decided 2026-11-08): a separate package with a service provider, config file, facade, `php artisan pyhp:setup` and `pyhp:info`, and session lifetime that matches the request or the worker, built on 0.4's shared sessions and 0.3's per-package requirements. On 2026-11-09 it moved again, to after 1.0, so it builds on a frozen API.

## v1.0.0: Stable

The goal of 1.0 is trust: people can build products on PyHP without fearing breaking changes.

Decisions (2026-11-09): 1.0 comes before `pyhp/laravel`; the way there is release candidates (`1.0.0-rc1`, more if needed, then `1.0.0`); production use is a soft goal (PyHP used in Ahmed's own real projects, feedback folded in), not a gate waiting for outside adopters; the docs website is VitePress on GitHub Pages; support is PHP 8.2+ (64-bit) and Python 3.11+ (Python 3.10 reached end of life in October 2026, so 1.0 drops it; decided 2026-11-09). Deprecations warn with `E_USER_DEPRECATED` for at least one minor release; end-of-life PHP and Python versions may be dropped in a minor release; fixes go into the latest minor release only (see [docs/17-compatibility.md](docs/17-compatibility.md)).

1. **Public API review.** Every public class, method, option and exception is listed, checked for consistent naming and behaviour, and either kept, renamed or marked `@internal`. Breaking cleanups happen now, before the freeze, each one in the CHANGELOG with how to upgrade.
2. **A written compatibility promise.** What counts as public API, what SemVer means for PyHP (PHP API, `extra.pyhp` keys, CLI commands and exit codes, the Python-side `pyhp` module), and the deprecation rule: nothing public is removed without a deprecation in a minor release first.
3. **Stable wire protocol.** The protocol is documented frame by frame, with a version handshake that fails clearly on a mismatch; changes after 1.0 stay backward-compatible within a major version.
4. **Verified everywhere it claims to work.** CI on Linux, Windows and macOS for the oldest and newest supported PHP (8.2 and the newest release) and Python (3.11 and the newest release), plus the real-world setup tests.
5. **Quality bars.** Coverage stays above 80%, aiming for 90%; mutation testing on the core (Infection for PHP, mutmut for Python); PHPStan max, mypy strict; a security review of the worker and its socket handling.
6. **Documentation website.** VitePress built from `docs/` and published to GitHub Pages by CI, with search, guides for common libraries and long-running PHP, an upgrade guide, and published benchmarks.
7. **From the parking lot** (decided 2026-11-09):
   - `pyhp info --json`, a machine-readable report whose fields are covered by the promise;
   - Linux on ARM and Windows on ARM in CI (Apple Silicon is already covered);
   - a ready-made Docker image with PHP, Python, uv and PyHP;
   - faster transfer of large NumPy arrays (binary frames instead of JSON).
8. **Release candidates.** `1.0.0-rc1` once the API is frozen; only fixes after that; `1.0.0` when an RC has been used in real projects without API changes.

## Parking lot

Ideas not scheduled yet:

- Symfony bundle (after `pyhp/laravel` shows what a framework package needs).
- Callbacks from Python background threads (a thread-safe callback mode).
- conda environments in `pyhp setup`.
