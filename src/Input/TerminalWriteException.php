<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

final class TerminalWriteException extends TerminalException
{
    public function __construct(public readonly int $bytesWritten, int $totalBytes)
    {
        parent::__construct(sprintf(
            'Terminal output stopped after %d of %d bytes',
            $bytesWritten,
            $totalBytes,
        ));
    }
}
