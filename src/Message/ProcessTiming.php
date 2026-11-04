<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

/**
 * Both on the program's clock, the one TickMsg timestamps use.
 */
final readonly class ProcessTiming
{
    public function __construct(
        public float $startedAt,
        public float $finishedAt,
    ) {
    }
}
