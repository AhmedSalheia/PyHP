# v1.0 mutation testing: first results and follow-up

## Python worker (mutmut, 2026-11-09)

1,012 mutants. First run: 74% caught. 73 new tests (`tests/python/test_edges.py` and a few in `test_transport.py`) for exact limits (64-bit integers, nesting depth, frame and authentication sizes, output chunks), callback counters and error messages: **86%** (869 of 1,012). Most of the rest change behaviour in no observable way, or only an error message's wording.

Second run (2026-11-13, in CI): **85.9%**. Most survivors changed only the wording of an error message (mutmut turns `"text"` into `"XXtextXX"`), because the tests checked those messages with `in` or `match`. `tests/python/test_messages.py` now pins every message the worker writes word for word: malformed requests, values that can't be decoded or copied into PHP, callback misuse, vectorized callbacks that answer wrongly, oversized frames, the start-up and fatal-frame lines in the log, and the Python version check at its boundary. Local run after that: **91.5%** (926 of 1,012 killed, 4 suspicious), plus 3 more killed by the last two tests (checked by hand), so about **92%**.

The remaining ~80 are not worth a test: type aliases and constants (`Json`, `Request`, `RECURSION_LIMIT`), counters whose start value nobody sees (`_next_id`, `_active_requests`), caches that only change speed (`key_sets`, `cacheable`), defaults of fields PHP always sends, environment defaults, socket options (`TCP_NODELAY`, `SO_EXCLUSIVEADDRUSE` on Linux), and float/int checks where the mutated branch gives the same result.

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

Second run (2026-11-13, after the tests above): **93.00%**: 2,521 caught, 90 untested, 100 uncovered, 3 timeouts. The real gaps left were small; `tests/php/SetupEdgesTest.php` covers them: a too-old Python found before a suitable one (the search has to go on, not stop), `pyhp lock` without uv (the exact "only for Linux and Python 3.12" message, the explanation when pip can't resolve, nothing written then), `pip list` entries that aren't packages, probe answers in `pyhp info` with a missing or mistyped field, package versions that are neither a string nor null, the worker check's timing and the up-to-date lock in `--json`. Each was checked by applying the mutant by hand and seeing a test fail.

Left on purpose: constants and casts, exit codes and JSON depth limits that can't be reached, `continue`/`break` in loops where both give the same result, Windows-only code (`PATHEXT`) on Linux, the process runner's stdio descriptors, environment-dependent `pyhp info` rows (whether uv or Python is installed on the CI machine), `LockFile`'s option-line check (equivalent) and the `is_array()` check on `pip list` entries (`??` already gives null for anything else).

## Minimum scores

From now on the mutation workflow fails below **90%** for PHP and **85%** for the worker. The PHP job installs `requirements-dev.txt` so no test is skipped.
