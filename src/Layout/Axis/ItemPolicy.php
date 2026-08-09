<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

use InvalidArgumentException;

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
        if ($grow < 0 || $shrink < 0) {
            throw new InvalidArgumentException(sprintf('ItemPolicy weights must be non-negative, got grow %d and shrink %d', $grow, $shrink));
        }
        if ($max < $min) {
            throw new InvalidArgumentException(sprintf('ItemPolicy max must be greater than or equal to min, got max %d and min %d', $max, $min));
        }
    }
}
