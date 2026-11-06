<?php

declare(strict_types=1);

namespace PyHP\Exception;

/** A request or result is bigger than max_message_bytes. The session is still usable. */
final class MessageTooLargeException extends PyHPException {}
