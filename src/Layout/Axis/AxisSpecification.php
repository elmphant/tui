<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

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
    }
}
