<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * Resolved geometry for one axis: how big each slot is, and where it starts.
 *
 * @internal
 */
final readonly class AxisResult
{
    /**
     * @param list<int> $sizes
     * @param list<int> $offsets
     * @param int       $overflow cells the slots take beyond what the axis had
     */
    public function __construct(
        public array $sizes,
        public array $offsets,
        public int $overflow = 0,
    ) {
    }
}
