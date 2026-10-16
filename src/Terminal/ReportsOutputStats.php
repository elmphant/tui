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
     * Time spent waiting for the terminal to accept output — a PTY that stops
     * accepting bytes blocks the whole loop, and nothing else can observe that.
     */
    public function writeWaitSeconds(): float;
}
