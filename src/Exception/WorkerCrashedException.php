<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** The Python worker died in the middle of a session. The session is closed. */
final class WorkerCrashedException extends PyHPException {}
