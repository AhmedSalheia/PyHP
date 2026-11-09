# Tasks

## Scope 6: Laravel (pyhp/laravel v0.1.0)

Goal: a separate package, `pyhp/laravel` (repository `pyhp-php/laravel`), that makes PyHP feel native in Laravel, built on 0.4's shared sessions and 0.3's per-package requirements. See ROADMAP.md.

### Today: 2026-11-09
- [ ] Agree on pyhp/laravel design decisions (config file shape, facade and container bindings, session lifetime under FPM / Octane / queue workers, artisan commands, supported Laravel versions, testing helpers)

### Backlog (this scope)
- [ ] New repository `pyhp-php/laravel` with CI, hooks and GitFlow branches
- [ ] Service provider, `config/pyhp.php`, facade
- [ ] Session lifetime that matches the request, the Octane worker or the queue worker
- [ ] `php artisan pyhp:setup` and `pyhp:info`
- [ ] Docs, CHANGELOG and release pyhp/laravel v0.1.0

### Done (this scope)

### Notes for next session
- v0.3.0 and v0.4.0 tasks are archived in docs/tasks/.
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
