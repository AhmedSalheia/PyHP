<?php

declare(strict_types=1);

// Charts with matplotlib, drawn from PHP data. Needs matplotlib:
//   python -m pip install matplotlib      (or: vendor/bin/pyhp setup)
// Run with: php examples/09_matplotlib_charts.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();

// Servers have no screen: pick the "Agg" backend BEFORE importing pyplot.
$py->import('matplotlib')->use('Agg');
$plt = $py->import('matplotlib.pyplot', as: 'plt');

$months = ['Jul', 'Aug', 'Sep', 'Oct', 'Nov'];
$revenue = [1250, 1480, 1310, 1720, 1960];
$orders = [42, 51, 47, 60, 66];

// subplots() returns a tuple (figure, axes): PHP gets it as a list of two proxies.
[$fig, $ax] = $plt->subplots(figsize: Py::tuple(8, 4));
$ax->bar($months, $revenue, color: '#4C78A8', label: 'Revenue');
$ax->set_ylabel('Revenue (USD)');
$ax->set_title('Revenue and orders per month');

// A second y axis for the order count.
$orderAxis = $ax->twinx();
$orderAxis->plot($months, $orders, color: '#F58518', marker: 'o', linewidth: 2, label: 'Orders');
$orderAxis->set_ylabel('Orders');

$fig->legend(loc: 'upper left', bbox_to_anchor: Py::tuple(0.1, 0.9));
$fig->tight_layout();

// 1. A PNG file, e.g. for an email or a PDF report.
$png = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-revenue.png';
$fig->savefig($png, dpi: 150);
echo "PNG written to {$png} (".filesize($png)." bytes)\n";

// 2. An SVG string, to put straight into a web page without saving a file.
$buffer = $py->import('io')->StringIO();
$fig->savefig($buffer, format: 'svg');
$svg = $buffer->getvalue();
$html = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-revenue.html';
file_put_contents($html, "<!doctype html><title>Revenue</title><h1>Monthly revenue</h1>\n".$svg);
echo "Web page with an inline SVG chart written to {$html}\n";

// 3. PNG bytes in memory, e.g. for an HTTP response: Python bytes arrive as a PHP string.
$bytes = $py->import('io')->BytesIO();
$fig->savefig($bytes, format: 'png');
$data = $bytes->getvalue();
echo 'In-memory PNG: '.strlen($data).' bytes, starts with '.bin2hex(substr($data, 0, 4))."\n";
// In a web controller: header('Content-Type: image/png'); echo $data;

// Free the figure's memory; long-running PHP processes should always do this.
$plt->close($fig);
$py->close();
