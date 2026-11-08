# Tasks

## Scope 5: Long-running PHP and frameworks (v0.4.0)

Goal: PyHP is comfortable in production setups where PHP processes live for a long time (queue workers, Laravel Octane, RoadRunner, FrankenPHP). See ROADMAP.md.

Decisions (2026-11-08): restart is opt-in (`restart` option; the failed call still throws, the next one gets a fresh worker and the `onStart` hooks); session reuse is one shared session per process and name (`Py::shared()`), a pool can come later; `pyhp/laravel` moves to its own release after 0.4.0.

### Today: 2026-11-08
- [r] Worker recovery (`restart`, `onStart`, `restart()`, `ping()`) and shared sessions (`Py::shared()`), stale proxies refused (branch: feature/worker-recovery)

### Backlog (this scope)
- [ ] Live output: stream what Python prints to a PHP callback while a call runs
- [ ] `Py::vectorize()`: apply a PHP closure to a whole column or array in batches
- [ ] Docs, CHANGELOG and release 0.4.0

### Done (this scope)
- [x] Design decisions agreed (2026-11-08)

### Notes for next session
- v0.3.0 tasks are archived in docs/tasks/v0.3.0.md.
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
