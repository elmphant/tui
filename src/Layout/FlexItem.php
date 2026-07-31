<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Widget\Widget;

/**
 * @internal parent-owned sizing metadata for one Flex child
 */
final readonly class FlexItem
{
    public function __construct(
        public Widget $widget,
        public int $basis,
        public int $min,
        public int $max,
        public int $grow,
        public int $shrink,
    ) {
    }

    public function initialSize(): int
    {
        return min($this->max, max($this->min, $this->basis));
    }
}
