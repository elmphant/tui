<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Widget\Layout\ContentBasis;
use Elmphant\Tui\Widget\Widget;

final readonly class PinnedRows
{
    public function __construct(
        public Widget $widget,
        public bool $ruled = false,
        public ?string $caption = null,
        public int $gap = 0,
    ) {
    }

    public function rows(int $width): int
    {
        return $width < 1 ? 0 : ContentBasis::of($this->widget, $this->widget->sizing()->rows, Direction::Vertical, $width);
    }

    public function ruleRows(): int
    {
        return $this->ruled ? 1 : 0;
    }
}
