# Tasks

## Scope 4: Ecosystem and data fidelity (v0.3.0)

Decisions (2026-11-08): Composer packages declare Python requirements in their own `extra.pyhp`; the root project can tighten versions, ignore a package's requirements (`extra.pyhp.ignore-packages`) and override versions (`extra.pyhp.overrides`, uv only); `pyhp lock` writes `pyhp.lock` (cross-platform with uv); the starter lives in its own repository, `pyhp-php/starter`.

### Today: 2026-11-08
- [~] Real-world `pyhp setup` tests in CI: Linux, Windows, macOS × uv / system Python (branch: chore/setup-smoke-tests)

### Backlog (this scope)
- [ ] Lossless numbers: integer-like Python numbers (SymPy `Integer`, ...) become PHP `int` when they fit in 64 bits
- [ ] Python requirements from installed Composer packages (`vendor/composer/installed.json`), shown per package in `setup` and `info`
- [ ] Root control: `extra.pyhp.ignore-packages` and `extra.pyhp.overrides` (uv only)
- [ ] Clear conflict reports naming the Composer packages behind each requirement
- [ ] `pyhp lock` and `pyhp.lock` (universal with uv, current OS with pip)
- [ ] `pyhp setup --exact`: the venv matches the lock or requirements exactly
- [ ] Starter project: new repository `pyhp-php/starter`, `composer create-project pyhp/starter`
- [ ] Docs, CHANGELOG and release 0.3.0

### Done (this scope)

### Notes for next session
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
