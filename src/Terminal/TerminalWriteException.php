<?php

declare(strict_types=1);

namespace Elmphant\Tui\Terminal;

final class TerminalWriteException extends TerminalException
{
    /**
     * @param bool $stalled the terminal stopped reading for a while, rather than going away
     */
    public function __construct(public readonly int $bytesWritten, int $totalBytes, public readonly bool $stalled = false)
    {
        parent::__construct(sprintf(
            'Terminal output stopped after %d of %d bytes',
            $bytesWritten,
            $totalBytes,
        ));
    }
}
