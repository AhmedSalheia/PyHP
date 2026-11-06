<?php

declare(strict_types=1);

namespace PyHP;

/** What happens to text that Python code prints. */
enum OutputMode: string
{
    /** Printed text is echoed to PHP's output after each call returns. */
    case Echo = 'echo';

    /** Printed text is collected; read it with Session::takeOutput(). */
    case Capture = 'capture';
}
