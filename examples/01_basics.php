<?php

declare(strict_types=1);

// Run with: php examples/01_basics.php

require __DIR__.'/bootstrap.php';

use PyHP\Exception\PythonException;
use PyHP\Py;

$py = Py::session();

// Statements: state stays in the session between calls.
$py->exec('greeting = "Hello"');
$py->exec('print(greeting, "from Python")');      // echoed to PHP's output

// Expressions come back as PHP values.
$sum = $py->eval('sum(range(101))');
echo "sum(range(101)) = {$sum}\n";

// Python errors become PHP exceptions, and the session keeps working.
try {
    $py->eval('1 / 0');
} catch (PythonException $error) {
    echo "Caught {$error->pythonType}: {$error->pythonMessage}\n";
}

echo 'Still alive: '.$py->eval('"yes"')."\n";
echo 'Python '.$py->info()['python']."\n";

$py->close();
