# Changelog

All notable changes to PyHP are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Persistent Python worker over a loopback socket with a token handshake and protocol version 1.
- `Session` with `exec`, `execFile`, `eval`, `import`, `vars`, `functions`, output capture and log access.
- `PyObject` proxies: attributes, methods with keyword arguments, calls, item access, lazy iteration, string conversion, automatic release.
- `Py` static facade, `Py::tuple/slice/dict` value helpers, `Py::len/repr/type/toPhp`.
- Conversion rules between PHP and Python values (see README).
- Exception hierarchy, timeouts, crash detection, message size limits.
- Examples for basics, vars/functions, pandas and NumPy; overhead benchmark.

### Verified
- Linux: PHP 8.3 / Python 3.13 / pandas 3.0 / NumPy 2.5.
- Windows: Pest 101/101 (88% coverage), PHPStan max, Pint; worker tests on Python 3.11.
