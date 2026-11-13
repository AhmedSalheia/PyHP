# 17. Versions and compatibility

PyHP follows [Semantic Versioning](https://semver.org/). This page says exactly what that promises: what you can rely on between releases, what can change, and how changes are announced.

## Before 1.0

Releases `0.x` are not covered by this promise: a minor release (0.4 → 0.5) may break things. Every breaking change is still listed in [`CHANGELOG.md`](../CHANGELOG.md) as **Breaking**, with an upgrade note. The promise below applies from **1.0.0** on, and to the release candidates leading up to it.

## What the version number means

| Release | Example | What can change |
|---|---|---|
| Patch | 1.2.0 → 1.2.1 | Bug fixes only. |
| Minor | 1.2 → 1.3 | New features, deprecations, dropping end-of-life PHP or Python versions. Nothing you use breaks. |
| Major | 1.x → 2.0 | Breaking changes, each with an upgrade note. Deprecated features are removed here. |

Use `"pyhp/pyhp": "^1.0"` in `composer.json` to get every fix and feature of 1.x and never a breaking change.

## What is covered

The **public API** is everything below. A change that could break code using it only happens in a major release.

* **PHP classes** in `PyHP\`, `PyHP\Value\` and `PyHP\Exception\`: their public constants, properties and methods, **including parameter names** (PHP's named arguments make them part of the API), types and defaults. The exact list is [`tests/php/public-api.txt`](../tests/php/public-api.txt); a test fails whenever the code and that list differ.
* **Options** of `Config`, `Py::config()` and `Py::session()`: the keys, their types, and what they do. Default values change only in a major release.
* **The `pyhp` command**: its commands (`setup`, `lock`, `info`, `help`), their options, the [exit codes](03-command-line.md#exit-codes), and the fields of [`pyhp info --json`](03-command-line.md#pyhp-info---json) (new fields can be added in minor releases; existing ones keep their meaning).
* **`composer.json` keys** under `extra.pyhp`: `python`, `venv`, `requirements`, `ignore-packages`, `overrides`, in your project and in the packages it installs.
* **The Python side**: the `pyhp` module (`PhpError` with `php_class` and `php_message`, and `PhpCallable`), and how your code runs in a session (the namespace is called `__pyhp__`).
* **Documented behaviour**: how values are converted, timeouts, output modes, restarts, and anything else these docs describe as how PyHP works.
* **Exceptions**: which exception class a documented failure throws. New exception classes may be added in minor releases; they always extend `PyHPException`.

## What is not covered

These can change in any release:

* Anything marked **`@internal`**, and everything in `PyHP\Internal`, `PyHP\Setup` and `PyHP\Console`. They're public in PHP's sense only because PyHP's own classes use them. Use `vendor/bin/pyhp` instead of the Setup classes, and `Config::detectPython()` instead of `Config::locatePython()`.
* **The wire protocol** between PHP and the Python worker. It's versioned and documented in [How PyHP works inside](15-internals.md), but both halves ship in the same package and always match. If they don't (a mixed installation), the session refuses to start with a clear error.
* **The text** of exception messages, of `pyhp setup` and `pyhp info` output, of `var_dump()` on proxies, and of `repr()` strings. Check exception classes and exit codes, not message text.
* **Performance**: timings in the docs are measurements, not promises.
* **Python library behaviour**: what pandas, NumPy or any other library returns is up to that library and its version.

All of PyHP's classes are `final` (except the base `PyHPException`), so adding a method to them never breaks your code.

## Deprecations

Before anything in the public API is removed or changed incompatibly, it's deprecated in a minor release first:

1. The `CHANGELOG.md` lists it under **Deprecated**, with what to use instead.
2. Its docblock gets `@deprecated`, so IDEs and PHPStan point at every use.
3. Using it triggers a PHP deprecation notice (`E_USER_DEPRECATED`) naming the replacement, so it shows up in your logs and test runs. It keeps working exactly as before.

It's removed in the next major release at the earliest. Upgrading is easiest when you clear the deprecation notices before moving to the next major version.

## Supported PHP and Python versions

PyHP 1.x needs **64-bit PHP 8.2 or newer** and **Python 3.11 or newer**, on Linux, Windows and macOS. CI tests the oldest and the newest supported version of each on all three systems.

* A PHP or Python version that has reached its **end of life upstream** may be dropped in a **minor** release, announced in the CHANGELOG. Composer then simply keeps you on the last PyHP release that supports your PHP.
* A version that is still supported upstream is only dropped in a **major** release.
* New PHP and Python versions are supported as soon as CI passes on them, usually in a patch or minor release.

## Fixes

Bug and security fixes go into the **latest minor release** of the current major version. Upgrading within a major version is always safe, so older minor releases don't get separate fixes. Report a security problem privately through GitHub's [security advisories](https://github.com/pyhp-php/pyhp/security/advisories/new), not in a public issue.

---

← [Development](16-development.md) · [Contents](README.md)
