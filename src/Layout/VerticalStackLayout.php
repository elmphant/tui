<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

/**
 * Resolved height and scrollbar geometry for one vertical stack viewport.
 *
 * @internal
 */
final readonly class VerticalStackLayout
{
    /**
     * One row of slack below the content, so the last line never sits against
     * the bottom border.
     */
    public const SCROLL_BOTTOM_PADDING = 1;

    /**
     * @param list<int> $heights
     */
    public function __construct(
        public array $heights,
        public int $totalHeight,
        public int $childWidth,
        public bool $hasScrollbar,
    ) {
    }

    public function maxScrollOffset(int $viewportHeight): int
    {
        return max(0, $this->totalHeight - $viewportHeight + self::SCROLL_BOTTOM_PADDING);
    }

    public function clampScrollOffset(int $scrollOffset, int $viewportHeight): int
    {
        return min($scrollOffset, $this->maxScrollOffset($viewportHeight));
    }
}
