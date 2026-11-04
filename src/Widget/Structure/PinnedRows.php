<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Layout\ContentBasis;
use Elmphant\Tui\Widget\Widget;

/**
 * A band pinned above or below a Panel's rows. It is drawn only: it receives no input or focus.
 */
final readonly class PinnedRows
{
    use Cloneable;

    private function __construct(
        public Widget $widget,
        public bool $ruled = false,
        public ?string $caption = null,
        public int $gap = 0,
    ) {
    }

    public static function of(Widget $widget): self
    {
        return new self($widget);
    }

    /**
     * A rule joins the border between the band and the rows. The caption rides on it, so there is none without a rule.
     */
    public function withRule(?string $caption = null): self
    {
        return $this->cloneWith(ruled: true, caption: $caption);
    }

    /**
     * Empty rows between the band and the rows, which shrink before the rows do.
     */
    public function withGap(int $gap): self
    {
        return $this->cloneWith(gap: max(0, $gap));
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
