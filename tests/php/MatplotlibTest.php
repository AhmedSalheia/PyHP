<?php

declare(strict_types=1);

use PyHP\Py;

/*
 * Optional: runs only when the interpreter under test has matplotlib.
 */

beforeEach(function (): void {
    $this->py = testSession();
    if (pythonHasModule('matplotlib')) {
        $this->py->import('matplotlib')->use('Agg');
        $this->plt = $this->py->import('matplotlib.pyplot', as: 'plt');
    }
});

afterEach(function (): void {
    $this->py->close();
});

test('a chart drawn from PHP data is saved as a PNG file', function (): void {
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-test-chart-'.bin2hex(random_bytes(4)).'.png';
    [$fig, $ax] = $this->plt->subplots(figsize: Py::tuple(4, 3));
    $ax->bar(['a', 'b', 'c'], [3, 1, 2]);
    $fig->savefig($file);
    $this->plt->close($fig);

    $header = (string) file_get_contents($file, length: 8);
    unlink($file);

    expect($header)->toBe("\x89PNG\r\n\x1a\n");
})->skip(fn () => ! pythonHasModule('matplotlib'), 'matplotlib is not installed');

test('a chart can be rendered to an SVG string and to PNG bytes in memory', function (): void {
    $fig = $this->plt->figure();
    $fig->add_subplot()->plot([1, 2, 3], [1, 4, 9]);
    $io = $this->py->import('io');
    $text = $io->StringIO();
    $bytes = $io->BytesIO();
    $fig->savefig($text, format: 'svg');
    $fig->savefig($bytes, format: 'png');
    $this->plt->close($fig);

    expect($text->getvalue())->toContain('<svg')
        ->and(substr($bytes->getvalue(), 0, 4))->toBe("\x89PNG");
})->skip(fn () => ! pythonHasModule('matplotlib'), 'matplotlib is not installed');
