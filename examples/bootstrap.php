<?php

declare(strict_types=1);

// Shared setup for the examples. Point PYHP_PYTHON at your interpreter, e.g.
//   Windows:  set PYHP_PYTHON=C:\path\to\venv\Scripts\python.exe
//   Linux:    export PYHP_PYTHON=/path/to/venv/bin/python

require __DIR__.'/../vendor/autoload.php';

use PyHP\Py;

$python = getenv('PYHP_PYTHON');
if (is_string($python) && $python !== '') {
    Py::config(['python' => $python]);
}
