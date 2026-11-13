# Tasks

## Scope 6: Stable (v1.0.0)

Goal: people can build products on PyHP without fearing breaking changes: a reviewed and frozen public API and protocol, a written compatibility promise, quality bars and a docs website, released through release candidates. See ROADMAP.md.

Decisions (2026-11-13): the Docker image is a base for apps (PHP + Python + uv + Composer; PyHP stays a Composer dependency), `cli` and `fpm` variants of PHP 8.5, amd64 and arm64, published to ghcr.io/pyhp-php/pyhp. Decisions (2026-11-09): deprecations warn with `E_USER_DEPRECATED` for at least one minor release before removal in a major; end-of-life PHP/Python versions may be dropped in a minor; fixes only for the latest minor. 1.0 comes before `pyhp/laravel`; release candidates first (`1.0.0-rc1`, then `1.0.0`); production use is a soft goal (Ahmed's own projects), not a gate; docs website with VitePress on GitHub Pages; support is PHP 8.2+ (64-bit) and Python 3.11+ (3.10 is end-of-life; dropped before 1.0). CI: newest PHP 8.5 required, 8.6 as a non-blocking job until it's final (19 Nov 2026).

### Today: 2026-11-13

### Backlog (this scope)
- [ ] Docs website: VitePress from docs/, GitHub Pages deploy in CI, search, upgrade guide, benchmarks page
- [ ] Faster transfer of large NumPy arrays: binary frames instead of JSON
- [ ] Use PyHP in a real project of Ahmed's and fold in the feedback (soft goal)
- [ ] Release 1.0.0-rc1, then 1.0.0

### Done (this scope)
- [x] Exact worker error messages and the last PHP mutation gaps: PHP 93.55%, worker 91.6% (PR #53)
- [x] PHP mutation gaps: golden output tests for info/setup/lock, fake-worker start-up tests; minimum scores 90% (PHP) and 85% (worker) (test/mutation-gaps)
- [x] Mutation testing runs from mutation/* branches (PR #51)
- [x] Public API guard test independent of how PHP prints self (PR #50)
- [x] Docker base image ghcr.io/pyhp-php/pyhp, cli and fpm (PR #49)
- [x] Linux on ARM and Windows on ARM in CI (PR #48)
- [x] `pyhp info --json` (PR #47)
- [x] Parking lot triage: `pyhp info --json`, ARM in CI, Docker image and faster NumPy transfer go into 1.0; Symfony bundle, background-thread callbacks and conda stay after it (2026-11-09)
- [x] Mutation testing: Pest --mutate and mutmut, weekly workflow; worker tests for the gaps found (PR #46)
- [x] Security review of the worker and its socket: docs/tasks/v1-security-review.md (PR #45)
- [x] CI matrix: oldest and newest PHP and Python on all three systems, PHP 8.6 early-warning job; Python 3.11 minimum (PR #44)
- [x] Wire protocol documented frame by frame, with a test that keeps it complete (PR #43)
- [x] Compatibility promise: docs/17-compatibility.md (PR #42)
- [x] `@internal` on everything that isn't public API, the public API list and its guard test (PR #41)
- [x] Breaking cleanups from the API review: `Py::shared()` name required, `Session::configuration()`, option `auto_restart`, CLI exit code 2 for wrong usage (PR #40)
- [x] Public API review written and decided: docs/tasks/v1-api-review.md (PR #39)

### Notes for next session
- When the windows-11-arm jobs have been green for a while, make them required (drop the continue-on-error) and update docs/13 and docs/16.
- Mutation scores (2026-11-13): PHP 93.55% (was 93.00%), worker 91.6% (was 85.9%), both measured in CI on mutation/exact. Remaining survivors are equivalent or not worth a test; see docs/tasks/v1-mutation-report.md.
- After PHP 8.6.0 is released (19 Nov 2026): make 8.6 the required newest PHP in CI (replacing 8.5, dropping the experimental job) and update docs/13 and docs/16.
- v0.3.0 and v0.4.0 tasks are archived in docs/tasks/.
- `pyhp/laravel` follows 1.0 (Scope 7).
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
