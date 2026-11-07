<?php

declare(strict_types=1);

use PyHP\Py;

/*
 * Optional: runs only when the interpreter under test has Pillow.
 */

beforeEach(function (): void {
    $this->py = testSession();
    if (pythonHasModule('PIL')) {
        $this->Image = $this->py->import('Image', from: 'PIL');
    }
});

afterEach(function (): void {
    $this->py->close();
});

test('thumbnails keep the aspect ratio', function (): void {
    $image = $this->Image->new('RGB', Py::tuple(1600, 1000), color: 'white');
    $image->thumbnail(Py::tuple(400, 400));

    expect($image->size)->toBe([400, 250]);
})->skip(fn () => ! pythonHasModule('PIL'), 'Pillow is not installed');

test('images travel between PHP and Python as binary strings', function (): void {
    $io = $this->py->import('io');
    $buffer = $io->BytesIO();
    $this->Image->new('RGB', Py::tuple(20, 10), color: 'red')->save($buffer, format: 'WEBP');
    $webp = $buffer->getvalue();

    $reopened = $this->Image->open($io->BytesIO($webp));

    expect(substr($webp, 0, 4))->toBe('RIFF')
        ->and(substr($webp, 8, 4))->toBe('WEBP')
        ->and($reopened->format)->toBe('WEBP')
        ->and($reopened->size)->toBe([20, 10]);
})->skip(fn () => ! pythonHasModule('PIL'), 'Pillow is not installed');
