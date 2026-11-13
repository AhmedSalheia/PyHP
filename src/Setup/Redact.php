<?php

declare(strict_types=1);

namespace PyHP\Setup;

/**
 * Hides credentials in URLs before text is shown: a requirement such as
 * "pkg @ https://user:token@example.com/pkg.whl" or "--index-url https://token@host/simple"
 *
 * appears as "https://***@example.com/...". Only output is redacted; commands
 * still get the real URL.
 *
 * @internal
 */
final class Redact
{
    public static function urls(string $text): string
    {
        return (string) preg_replace('~\b([a-z][a-z0-9+.\-]*://)[^\s/@"\']+@~i', '$1***@', $text);
    }
}
