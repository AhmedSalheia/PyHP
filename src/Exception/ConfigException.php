<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** An option passed to Py::config(), Py::session() or Config::fromArray() is invalid. */
final class ConfigException extends PyHPException {}
