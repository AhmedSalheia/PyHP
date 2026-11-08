# PyHP Roadmap

Goal: make Python's libraries (data analysis, science, NLP, machine learning, and everything else) usable from PHP, in PHP syntax, with no compiled extension, on Linux, Windows and macOS, for PHP developers who don't need to know Python well.

| # | Scope | Version | Status | Summary |
|---|---|---|---|---|
| 1 | Foundation | v0.1.0 | released 2026-11-07 | Worker, sessions, proxies, `vars`/`functions`/`classes`/`modules`, Python-style imports, PHP callbacks, fast table export, CI on three systems, MIT license. |
| 2 | New home | v0.1.1 | released 2026-11-07 | Package renamed to `pyhp/pyhp`, repository moved to `pyhp-php/pyhp`. |
| 3 | Tooling | v0.2.0 | released 2026-11-07 | `pyhp setup` (uv or venv/pip), `pyhp info`, automatic `.venv` detection, `Py::ref()`, examples 06–11, numbered docs. |
| 4 | Ecosystem and data fidelity | v0.3.0 | released 2026-11-08 | Python requirements declared by any Composer package, exact environments, lossless numbers, real-world setup tests. |
| 5 | Long-running PHP and frameworks | v0.4.0 | in progress | Worker reuse and recovery for queue workers and app servers, live output, faster callbacks, a Laravel package. |
| 6 | Stable | v1.0.0 | goal | Frozen public API and protocol, documented guarantees, docs website, proven in production. |

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

## v0.4.0: Long-running PHP and frameworks

PyHP becomes comfortable in production setups where PHP processes live for a long time.

1. **Worker recovery.** An option to restart a crashed or timed-out worker automatically, with a hook to rebuild session state (imports, loaded models). `Session::isAlive()` and a cheap health check.
2. **Session reuse.** A small pool or a "shared session per process" helper for queue workers, Laravel Octane, RoadRunner and FrankenPHP, so expensive imports and models load once per process instead of once per job. Clear rules for what is safe to share.
3. **Live output.** Optionally stream what Python prints to PHP while a call runs (a callback), instead of only when it ends. Useful for long jobs and progress bars.
4. **Faster callbacks.** `Py::vectorize()`: a PHP closure applied to a whole column or array in batches, one round trip per batch instead of per value.
5. **`pyhp/laravel`.** A separate package: service provider, config file, facade, `php artisan pyhp:setup` and `pyhp:info`, and session lifetime that matches the request or the worker. It uses v0.3's per-package requirements.

## v1.0.0: Stable

The goal of 1.0 is trust: people can build products on PyHP without fearing breaking changes.

- **Frozen public API.** Everything public is reviewed, named consistently and documented; `@internal` marks the rest. From 1.0 on, breaking changes only happen in a new major version, after a deprecation period.
- **Stable wire protocol**, versioned and documented, so the worker and the PHP side can evolve safely.
- **Verified everywhere it claims to work.** CI on Linux, Windows and macOS for every supported PHP (8.2+) and Python (3.10+) version, including the newest releases.
- **Quality bars.** Coverage stays above 80% (aiming for 90%), mutation testing on the core, PHPStan max and mypy strict, a security review of the worker and its socket handling.
- **Documentation website** built from `docs/`, with guides for common libraries and frameworks, and published benchmarks.
- **Proven in production** by real projects, with feedback folded in before the API is frozen.

## Parking lot

Ideas not scheduled yet:

- Symfony bundle (after `pyhp/laravel` shows what a framework package needs).
- Callbacks from Python background threads (a thread-safe callback mode).
- Faster transfer of large NumPy arrays (binary frames instead of JSON), if users need it.
- conda environments in `pyhp setup`.
- A ready-made Docker image with PHP, Python, uv and PyHP.
- `pyhp info --json` for scripts and support tools.
- Windows on ARM and Linux on ARM in CI.
