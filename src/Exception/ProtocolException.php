<?php

declare(strict_types=1);

namespace PyHP\Exception;

/**
 * PHP and the worker disagreed about the wire protocol. This indicates a PyHP
 * bug or a mismatched worker script, not a problem in your Python code.
 */
final class ProtocolException extends PyHPException {}
