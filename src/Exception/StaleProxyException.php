<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** A proxy was used after its session restarted the worker, so the Python object it stood for is gone. */
final class StaleProxyException extends PyHPException
{
    public static function restarted(): self
    {
        return new self(
            'This Python object belonged to a worker that has since restarted, so it no longer exists. '
            .'Get it again, for example in an onStart() hook.'
        );
    }
}
