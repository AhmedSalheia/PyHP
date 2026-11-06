<?php

declare(strict_types=1);

namespace PyHP\Exception;

use RuntimeException;

/**
 * Base class for every exception PyHP throws. Catch this to handle "anything
 * that went wrong while talking to Python".
 */
class PyHPException extends RuntimeException {}
