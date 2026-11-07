<?php

declare(strict_types=1);

/*
 * Python's standard library through proxies: nothing to install.
 */

beforeEach(function (): void {
    $this->py = testSession();
});

afterEach(function (): void {
    $this->py->close();
});

test('difflib suggests close matches and builds unified diffs', function (): void {
    $difflib = $this->py->import('difflib');
    $diff = $this->py->functions->list($difflib->unified_diff(["a\n", "b\n"], ["a\n", "c\n"], lineterm: "\n"));

    expect($difflib->get_close_matches('oliv soap', ['olive oil', 'olive soap', 'sage'], n: 1))->toBe(['olive soap'])
        ->and($diff)->toContain("-b\n")
        ->and($diff)->toContain("+c\n");
});

test('statistics returns plain PHP numbers', function (): void {
    $statistics = $this->py->import('statistics');

    expect($statistics->median([3, 1, 2]))->toBe(2)
        ->and($statistics->mean([1, 2, 3, 4]))->toBe(2.5)
        ->and($statistics->quantiles([1, 2, 3, 4, 5, 6, 7, 8], n: 4))->toBe([2.25, 4.5, 6.75]);
});

test('itertools generators are turned into arrays with list()', function (): void {
    $itertools = $this->py->import('itertools');

    expect($this->py->functions->list($itertools->product([1, 2], ['a', 'b'])))
        ->toBe([[1, 'a'], [1, 'b'], [2, 'a'], [2, 'b']]);
});

test('fractions and decimals stay exact', function (): void {
    $Fraction = $this->py->import('Fraction', from: 'fractions');
    $Decimal = $this->py->import('Decimal', from: 'decimal');

    expect((string) $Fraction(1, 3)->__add__($Fraction(1, 6)))->toBe('1/2')
        ->and((string) $Decimal('0.1')->__add__($Decimal('0.2')))->toBe('0.3');
});

test('ipaddress checks network membership', function (): void {
    $ipaddress = $this->py->import('ipaddress');
    $network = $ipaddress->ip_network('10.20.0.0/22');

    expect($network->__contains__($ipaddress->ip_address('10.20.3.15')))->toBeTrue()
        ->and($network->__contains__($ipaddress->ip_address('10.20.4.1')))->toBeFalse()
        ->and($network->num_addresses)->toBe(1024);
});
