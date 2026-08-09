<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * How an axis is divided, apart from what the slots themselves ask for.
 *
 * @internal
 */
final readonly class AxisSpecification
{
    public function __construct(
        public int $gap = 0,
    ) {
    }
}
