---
# The home page of the documentation website (https://pyhp-php.github.io/PyHP/).
# On GitHub, start with README.md in this folder instead.
layout: home

hero:
  name: PyHP
  text: Python libraries, from PHP
  tagline: pandas, NumPy, scikit-learn, spaCy and the rest of Python, used from PHP through proxies. A Composer package, no PHP extension.
  actions:
    - theme: brand
      text: Get started
      link: /introduction
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: GitHub
      link: https://github.com/pyhp-php/pyhp

features:
  - title: Real Python objects
    details: Objects live in a Python worker; PHP gets proxies that forward attribute reads, method calls, indexing and loops. DataFrames stay DataFrames.
  - title: One command to set up
    details: vendor/bin/pyhp setup builds the project's Python environment from composer.json, with uv when it's installed, and PyHP finds it by itself.
  - title: Fast where it counts
    details: Tables and NumPy arrays copy into PHP in one call, arrays as raw bytes. PHP closures can be called from Python, a batch at a time.
  - title: Safe to run
    details: Python runs in its own process. If it crashes, PHP gets an exception and carries on; the socket only accepts the PHP that started it.
---

## Read in order

Each page builds on the previous ones.

1. [Introduction](01-introduction.md)
2. [Installation](02-installation.md)
3. [The pyhp command](03-command-line.md)
4. [Core concepts](04-core-concepts.md)
5. [API reference](05-api-reference.md)
6. [Imports](06-imports.md)
7. [How values are converted](07-value-conversion.md)
8. [pandas and NumPy](08-pandas-and-numpy.md)
9. [PHP callbacks](09-php-callbacks.md)
10. [Using other Python libraries](10-libraries.md)
11. [Output and errors](11-output-and-errors.md)
12. [Memory and performance](12-memory-and-performance.md)
13. [Deployment, security and platforms](13-deployment-and-security.md)
14. [Limitations](14-limitations.md)
15. [How PyHP works inside](15-internals.md)
16. [Development](16-development.md)
17. [Versions and compatibility](17-compatibility.md)
18. [Upgrading](18-upgrading.md)
