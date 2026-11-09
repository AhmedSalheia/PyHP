# Tasks

## Scope 6: Stable (v1.0.0)

Goal: people can build products on PyHP without fearing breaking changes: a reviewed and frozen public API and protocol, a written compatibility promise, quality bars and a docs website, released through release candidates. See ROADMAP.md.

Decisions (2026-11-09): 1.0 comes before `pyhp/laravel`; release candidates first (`1.0.0-rc1`, then `1.0.0`); production use is a soft goal (Ahmed's own projects), not a gate; docs website with VitePress on GitHub Pages; support stays PHP 8.2+ (64-bit) and Python 3.10+.

### Today: 2026-11-09
- [ ] Public API review: inventory every public class, method, option, exception, CLI command and `extra.pyhp` key; propose keep / rename / `@internal` for each (written to docs/tasks/v1-api-review.md for Ahmed to decide)

### Backlog (this scope)
- [ ] Breaking cleanups agreed in the API review, each with a CHANGELOG upgrade note
- [ ] `@internal` on everything that isn't public API; PHPStan/tests make sure the public surface matches the list
- [ ] Compatibility promise: docs page on what is public, SemVer and the deprecation rule
- [ ] Wire protocol: document every frame; clear error on a version mismatch
- [ ] CI matrix: oldest and newest supported PHP and Python on Linux, Windows and macOS
- [ ] Mutation testing on the core (Infection, mutmut) and coverage towards 90%
- [ ] Security review of the worker and its socket handling
- [ ] Docs website: VitePress from docs/, GitHub Pages deploy in CI, search, upgrade guide, benchmarks page
- [ ] Triage the ROADMAP parking lot: what (if anything) belongs in 1.0
- [ ] Use PyHP in a real project of Ahmed's and fold in the feedback (soft goal)
- [ ] Release 1.0.0-rc1, then 1.0.0

### Done (this scope)

### Notes for next session
- v0.3.0 and v0.4.0 tasks are archived in docs/tasks/.
- `pyhp/laravel` follows 1.0 (Scope 7).
- ClickUp sync not set up for PyHP yet; TASKS.md is the source of truth.
