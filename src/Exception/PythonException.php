<?php

declare(strict_types=1);

namespace PyHP\Exception;

/**
 * Your Python code raised an exception. The session is still usable.
 *
 * The message is "Type: message", like the last line of a Python traceback.
 */
final class PythonException extends PyHPException
{
    public function __construct(
        public readonly string $pythonType,
        public readonly string $pythonMessage,
        public readonly string $pythonTraceback,
    ) {
        parent::__construct($pythonMessage === '' ? $pythonType : "{$pythonType}: {$pythonMessage}");
    }
}
