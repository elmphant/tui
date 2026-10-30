<?php

declare(strict_types=1);

namespace Elmphant\Tui\Terminal;

/**
 * Optional: a Program traces these when its terminal offers them.
 *
 * @internal
 */
interface ReportsOutputStats
{
    public function bytesWritten(): int;

    /**
     * Measure time blocked waiting for output, since a stalled PTY blocks the event loop too.
     */
    public function writeWaitSeconds(): float;
}
