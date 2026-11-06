<?php

declare(strict_types=1);

// A pandas workflow written in PHP syntax. Needs pandas in the chosen interpreter:
//   python -m pip install pandas
// Run with: php examples/03_pandas_report.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();
$pd = $py->import('pandas', as: 'pd');

// The DataFrame stays in Python; $sales is a proxy to it.
$sales = $pd->read_csv(__DIR__.'/data/sales.csv', parse_dates: ['date']);
echo 'Rows x columns: '.implode(' x ', $sales->shape)."\n";

// Derived column. PHP has no operator overloading, so use pandas' method forms (mul, gt, ...).
$sales['revenue'] = $sales['units']->mul($sales['unit_price']);

// A PHP value used as a filter threshold.
$py->vars->minRevenue = 5000;
$big = $sales[$sales['revenue']->ge($py->vars->minRevenue)];

// Summary, still inside pandas until to_dict() copies the small result to PHP.
$byRegion = $big->groupby('region')['revenue']->sum()->sort_values(ascending: false)->round(2);
echo "\nRevenue from orders >= {$py->vars->minRevenue}, by region:\n";
foreach ($byRegion->to_dict() as $region => $revenue) {
    printf("  %-6s %12s\n", $region, number_format($revenue, 2));
}

// Monthly pivot printed with pandas' own formatting.
$sales['month'] = $sales['date']->dt->strftime('%Y-%m');
$pivot = $sales->pivot_table(index: 'month', columns: 'product', values: 'units', aggfunc: 'sum');
echo "\nUnits per month and product:\n{$pivot}\n";

// Same thing as Python code, when that reads better. Both styles share state.
$py->vars->sales = $sales;
$top = $py->eval("sales.nlargest(3, 'revenue')[['date', 'region', 'product', 'revenue']]");
echo "\nTop 3 orders:\n{$top}\n";

// Export a file for other tools.
$out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp_revenue_by_region.csv';
$byRegion->to_csv($out);
echo "\nWrote {$out}\n";

$py->close();
