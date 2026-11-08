# Tasks

## Scope 5: Long-running PHP and frameworks (v0.4.0)

Goal: PyHP is comfortable in production setups where PHP processes live for a long time (queue workers, Laravel Octane, RoadRunner, FrankenPHP). See ROADMAP.md.

### Today: 2026-11-08
- [ ] Agree on v0.4.0 design decisions (recovery hook API, session reuse rules, live output API, vectorize semantics, Laravel package scope)

### Backlog (this scope)
- [ ] Worker recovery: optional automatic restart after a crash or timeout, with a hook that rebuilds session state; `Session::isAlive()` and a cheap health check
- [ ] Session reuse: a "one session per process" helper / small pool for queue workers and app servers, with clear rules for what is safe to share
- [ ] Live output: stream what Python prints to a PHP callback while a call runs
- [ ] `Py::vectorize()`: apply a PHP closure to a whole column or array in batches
- [ ] `pyhp/laravel`: new repository; service provider, config, facade, `pyhp:setup` / `pyhp:info` artisan commands, request/worker-scoped sessions
- [ ] Docs, CHANGELOG and release 0.4.0

### Done (this scope)

### Notes for next session
- v0.3.0 tasks are archived in docs/tasks/v0.3.0.md.
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
