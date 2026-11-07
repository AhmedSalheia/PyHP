<?php

declare(strict_types=1);

// Python's standard library from PHP: nothing to install.
// Run with: php examples/08_standard_library.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();

// 1. "Did you mean ...?" and text diffs with difflib.
$difflib = $py->import('difflib');
$products = ['olive oil', 'olive soap', "za'atar", 'sage tea', 'almonds'];
echo 'Did you mean: '.implode(', ', $difflib->get_close_matches('oliv soap', $products, n: 2))."\n";
echo 'Similarity of "colour" and "color": '.round($difflib->SequenceMatcher(null, 'colour', 'color')->ratio(), 2)."\n";

$before = ["Dear customer,\n", "Your order ships on Monday.\n", "Thanks!\n"];
$after = ["Dear customer,\n", "Your order ships on Tuesday.\n", "Thanks!\n"];
// unified_diff returns a generator: list() turns it into a PHP array of lines.
echo implode('', $py->functions->list($difflib->unified_diff($before, $after, fromfile: 'v1', tofile: 'v2')));

// 2. Statistics PHP doesn't ship with.
$statistics = $py->import('statistics');
$deliveryDays = [2, 3, 3, 4, 2, 7, 3, 5, 3, 2];
printf(
    "\nDelivery days: mean %.1f, median %s, stdev %.2f, quartiles %s\n",
    $statistics->mean($deliveryDays),
    $statistics->median($deliveryDays),
    $statistics->stdev($deliveryDays),
    json_encode($statistics->quantiles($deliveryDays, n: 4)),
);
printf("Correlation of ad spend and sales: %.3f\n", $statistics->correlation([10, 20, 30, 40], [12, 25, 29, 48]));

// 3. Every combination of options, e.g. for a test matrix.
$itertools = $py->import('itertools');
$matrix = $py->functions->list($itertools->product(['8.2', '8.4'], ['linux', 'windows', 'macos']));
echo "\nTest matrix: ".implode(', ', array_map(fn (array $pair): string => implode('/', $pair), $matrix))."\n";
echo 'Pairs of 4 teams: '.count($py->functions->list($itertools->combinations(['A', 'B', 'C', 'D'], 2)))."\n";

// 4. Exact arithmetic. Fractions and Decimals stay in Python: print them or convert them.
$Fraction = $py->import('Fraction', from: 'fractions');
$sum = $Fraction(1, 3)->__add__($Fraction(1, 6));
echo "\n1/3 + 1/6 = {$sum}\n";
$Decimal = $py->import('Decimal', from: 'decimal');
echo '0.1 + 0.2 = '.$Decimal('0.1')->__add__($Decimal('0.2'))." exactly (PHP floats give 0.30000000000000004)\n";

// 5. Word frequencies with collections.Counter.
$Counter = $py->import('Counter', from: 'collections');
$words = str_word_count(strtolower('The quick fox and the lazy dog and the cat'), 1);
echo 'Most common words: '.json_encode($Counter($words)->most_common(2))."\n";

// 6. IP addresses and networks with ipaddress.
$ipaddress = $py->import('ipaddress');
$office = $ipaddress->ip_network('10.20.0.0/22');
foreach (['10.20.3.15', '10.20.4.1'] as $ip) {
    $inside = $office->__contains__($ipaddress->ip_address($ip));
    echo "{$ip} is ".($inside ? 'inside' : 'outside')." {$office}\n";
}
echo "{$office} has {$office->num_addresses} addresses, from {$office->network_address} to {$office->broadcast_address}\n";

$py->close();
