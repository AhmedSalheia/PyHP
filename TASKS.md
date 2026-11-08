# Tasks

## Scope 5: Long-running PHP and frameworks (v0.4.0)

Goal: PyHP is comfortable in production setups where PHP processes live for a long time (queue workers, Laravel Octane, RoadRunner, FrankenPHP). See ROADMAP.md.

Decisions (2026-11-08): restart is opt-in (`restart` option; the failed call still throws, the next one gets a fresh worker and the `onStart` hooks); session reuse is one shared session per process and name (`Py::shared()`), a pool can come later; `pyhp/laravel` moves to its own release after 0.4.0. Live output: echo mode prints live, capture stays as it is, `onOutput()` sends chunks to a callback. `Py::vectorize()`: chunk closures and `elementwise: true`, used with `->pipe()`, keeps the Series index, default batch 1,000.

### Today: 2026-11-08
- [r] Live output: echo prints live, `onOutput()` callback (branch: feature/live-output)

### Backlog (this scope)
- [ ] `Py::vectorize()`: apply a PHP closure to a whole column or array in batches
- [ ] Docs, CHANGELOG and release 0.4.0

### Done (this scope)
- [x] Worker recovery and shared sessions (PR #33)
- [x] Design decisions agreed (2026-11-08)

### Notes for next session
- v0.3.0 tasks are archived in docs/tasks/v0.3.0.md.
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
