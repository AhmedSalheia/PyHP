<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** A value cannot be converted between PHP and Python. The session is still usable. */
final class ConversionException extends PyHPException {}
