<?php

declare(strict_types=1);

// Run with: php examples/02_vars_and_functions.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();

// Load Python definitions from a file into the session.
$py->execFile(__DIR__.'/python/pricing.py');

// Share PHP values with Python through vars ...
$py->vars->vatRate = 0.16;
$py->vars->cart = [
    ['name' => 'Keyboard', 'price' => 120.0, 'qty' => 1],
    ['name' => 'Cable', 'price' => 9.5, 'qty' => 3],
];

// ... and call Python functions with positional and named arguments.
$total = $py->functions->cart_total($py->vars->cart, discount: 0.1);
printf("Total incl. VAT: %.2f\n", $total);

// Python can read the PHP-provided variable directly.
$py->exec('print(f"VAT rate seen by Python: {vatRate:.0%}")');

// Create Python objects through classes: you get a proxy to a live Python object.
$invoice = $py->classes->Invoice('Ahmed');
$invoice->add('Monitor', 800);
$invoice->add('Stand', 45, qty: 2);
echo $invoice."\n";               // uses Python's __str__
echo 'Lines: '.count($invoice->lines)."\n";

$py->close();
