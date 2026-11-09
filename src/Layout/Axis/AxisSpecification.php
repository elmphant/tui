<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class AxisSpecification
{
    public function __construct(
        public int $gap = 0,
        public Justification $justification = Justification::Start,
        public Overflow $overflow = Overflow::Compress,
        public Remainder $remainder = Remainder::RearmostFirst,
        public Distribution $distribution = Distribution::AfterReservingFloors,
    ) {
        if ($gap < 0) {
            throw new InvalidArgumentException(sprintf('A gap must be non-negative, got %d', $gap));
        }
    }
}
