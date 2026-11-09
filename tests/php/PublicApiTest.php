<?php

declare(strict_types=1);

/*
 * Guards PyHP's public API: every public class, constant, property and method in
 * src/ that isn't marked @internal, with parameter names (named arguments are API
 * too), types and defaults. The list lives in tests/php/public-api.txt.
 *
 * When this test fails, the public API changed. If that was intended, update the
 * list with:  PYHP_UPDATE_API=1 vendor/bin/pest tests/php/PublicApiTest.php
 * and mention the change in CHANGELOG.md (breaking changes need an upgrade note).
 */

const PUBLIC_API_FILE = __DIR__.DIRECTORY_SEPARATOR.'public-api.txt';

/** @return list<string> one line per public API element, sorted */
function publicApi(): array
{
    $src = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
    $lines = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($src) + 1, -4);
        $name = 'PyHP\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        $class = new ReflectionClass($name);
        if (isInternal($class->getDocComment())) {
            continue;
        }
        array_push($lines, ...describeClass($class));
    }
    sort($lines);

    return $lines;
}

function isInternal(string|false $docComment): bool
{
    return $docComment !== false && str_contains($docComment, '@internal');
}

/** @return list<string> */
function describeClass(ReflectionClass $class): array
{
    $name = $class->getName();
    $kind = match (true) {
        $class->isEnum() => 'enum',
        $class->isInterface() => 'interface',
        default => ltrim(($class->isFinal() ? 'final ' : '').($class->isReadOnly() ? 'readonly ' : '').'class'),
    };
    $parent = $class->getParentClass();
    $interfaces = array_values(array_diff($class->getInterfaceNames(), ['UnitEnum', 'BackedEnum']));
    sort($interfaces);
    $lines = ["{$name}: {$kind}".($parent === false ? '' : " extends {$parent->getName()}")
        .($interfaces === [] ? '' : ' implements '.implode(', ', $interfaces))];
    // Members every enum gets from PHP itself.
    $builtIn = $class->isEnum() ? ['name', 'value', 'cases', 'from', 'tryFrom'] : [];

    foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $name || isInternal($constant->getDocComment())) {
            continue;
        }
        $lines[] = $constant->isEnumCase()
            ? "{$name}::{$constant->getName()} case"
            : "{$name}::{$constant->getName()} const";
    }
    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $name || isInternal($property->getDocComment())
            || in_array($property->getName(), $builtIn, true)) {
            continue;
        }
        $lines[] = "{$name}::\${$property->getName()}: ".($property->isReadOnly() ? 'readonly ' : '').$property->getType();
    }
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $name || isInternal($method->getDocComment())
            || in_array($method->getName(), $builtIn, true)) {
            continue;
        }
        $parameters = array_map(describeParameter(...), $method->getParameters());
        $lines[] = "{$name}::".($method->isStatic() ? 'static ' : '')."{$method->getName()}(".implode(', ', $parameters).')'
            .($method->hasReturnType() ? ": {$method->getReturnType()}" : '');
    }

    return $lines;
}

function describeParameter(ReflectionParameter $parameter): string
{
    $text = trim(($parameter->hasType() ? $parameter->getType().' ' : '')
        .($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName());
    if ($parameter->isDefaultValueAvailable()) {
        $text .= ' = '.($parameter->isDefaultValueConstant()
            ? $parameter->getDefaultValueConstantName()
            : describeDefault($parameter->getDefaultValue()));
    }

    return $text;
}

function describeDefault(mixed $value): string
{
    return match (true) {
        is_object($value) => 'new '.$value::class,
        $value === [] => '[]',
        $value === null => 'null',
        default => var_export($value, true),
    };
}

test('the public API matches tests/php/public-api.txt', function (): void {
    $current = implode("\n", publicApi())."\n";
    if (getenv('PYHP_UPDATE_API') === '1') {
        file_put_contents(PUBLIC_API_FILE, $current);
    }
    $expected = is_file(PUBLIC_API_FILE) ? str_replace("\r\n", "\n", (string) file_get_contents(PUBLIC_API_FILE)) : '';
    $expectedLines = array_filter(explode("\n", $expected));
    $currentLines = array_filter(explode("\n", $current));

    $added = array_values(array_diff($currentLines, $expectedLines));
    $removed = array_values(array_diff($expectedLines, $currentLines));

    expect(['added' => $added, 'removed' => $removed])->toBe(['added' => [], 'removed' => []]);
});

test('setup and console classes are not public API', function (): void {
    $public = array_filter(publicApi(), static fn (string $line): bool => str_starts_with($line, 'PyHP\\Setup\\')
        || str_starts_with($line, 'PyHP\\Console\\'));

    expect(array_values($public))->toBe([]);
});

test('implementation details of public classes are internal', function (string $line): void {
    expect(implode("\n", publicApi()))->not->toContain($line);
})->with([
    'Config::locatePython' => ['PyHP\\Config::static locatePython('],
    'PyObject constructor' => ['PyHP\\PyObject::__construct('],
    'Vectorized::$callback' => ['PyHP\\Value\\Vectorized::$callback'],
    'Session::request' => ['PyHP\\Session::request('],
]);
