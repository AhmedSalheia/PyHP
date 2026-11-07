<?php

declare(strict_types=1);

// Shared setup for the examples. Install the Python packages they use once, from
// the PyHP folder:
//   php bin/pyhp setup
// PyHP then finds that .venv by itself. To use another interpreter, set
// PYHP_PYTHON (e.g. set PYHP_PYTHON=C:\path\to\python.exe) or call
// Py::config(['python' => ...]).

require __DIR__.'/../vendor/autoload.php';
