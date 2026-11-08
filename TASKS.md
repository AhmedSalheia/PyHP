# Tasks

## Scope 4: Ecosystem and data fidelity (v0.3.0)

Decisions (2026-11-08): Composer packages declare Python requirements in their own `extra.pyhp`; the root project can tighten versions, ignore a package's requirements (`extra.pyhp.ignore-packages`) and override versions (`extra.pyhp.overrides`, uv only); `pyhp lock` writes `pyhp.lock` (cross-platform with uv); the starter lives in its own repository, `pyhp-php/starter`.

### Today: 2026-11-08
- [r] `pyhp lock`, `pyhp.lock` and `pyhp setup --exact`, with the smoke test extended to cover them (branch: feature/lock-and-exact)

### Backlog (this scope)
- [ ] Starter project: new repository `pyhp-php/starter`, `composer create-project pyhp/starter`
- [ ] Docs, CHANGELOG and release 0.3.0

### Done (this scope)
- [x] Python requirements from installed Composer packages, root control and conflict reports (PR #29)
- [x] Lossless numbers: integer-like Python numbers become PHP `int` when they fit in 64 bits (PR #28)
- [x] Real-world `pyhp setup` tests in CI: Linux, Windows, macOS × uv / system Python (PR #27)

### Notes for next session
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
