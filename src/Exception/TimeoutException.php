<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** A call took longer than the configured timeout. The worker was killed and the session is closed. */
final class TimeoutException extends PyHPException {}
