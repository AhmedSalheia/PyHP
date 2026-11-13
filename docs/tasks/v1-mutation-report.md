# v1.0 mutation testing: first results and follow-up

## Python worker (mutmut, 2026-11-09)

1,012 mutants. First run: 74% caught. 73 new tests (`tests/python/test_edges.py` and a few in `test_transport.py`) for exact limits (64-bit integers, nesting depth, frame and authentication sizes, output chunks), callback counters and error messages: **86%** (869 of 1,012). Most of the rest change behaviour in no observable way, or only an error message's wording.

## PHP library (Pest `--mutate`, 2026-11-13)

2,714 mutants. First run: **82%** caught (2,223), 345 untested, 145 uncovered, 21 tests skipped because the job only installed pandas and NumPy.

| Kind | Count | |
|---|---:|---|
| Not real gaps: constants and property defaults (71), `assert()` lines (12), `(string)` casts (13), Windows-only code on Linux (9), JSON errors that can't happen (10) | 115 | left as they are |
| `pyhp info` output and `--json` fields | 152 | golden output tests: `InfoOutputTest` |
| `pyhp setup` / `pyhp lock` output and branches | 111 | golden output tests and branch tests: `InstallerOutputTest` |
| Requirements, lock file, process runner | 40 | `ProjectRequirementsTest`, `LockFileTest`, `ProcessRunnerTest`, `SetupConfigTest` |
| Worker start-up failures | 39 | `WorkerStartupTest` with a fake worker (`tests/php/fixtures/fake-worker`) |
| Command-line parsing | 25 | `ConsoleTest` |
| A callback reply that's too large | 8 | `CallbackTest` |

## Minimum scores

From now on the mutation workflow fails below **90%** for PHP and **85%** for the worker. The PHP job installs `requirements-dev.txt` so no test is skipped.
