<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * What one slot along an axis asks for: where it starts, how far it may move in
 * either direction, and how eagerly it takes or gives up space.
 *
 * @internal
 */
final readonly class ItemPolicy
{
    public function __construct(
        public int $basis = 0,
        public int $min = 0,
        public int $max = PHP_INT_MAX,
        public int $grow = 0,
        public int $shrink = 0,
    ) {
    }
}
