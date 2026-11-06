<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** The Python worker could not be started (interpreter missing, crashed on start, handshake failed). */
final class WorkerStartException extends PyHPException {}
