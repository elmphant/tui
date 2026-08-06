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
    public const SCROLL_BOTTOM_PADDING = ScrollWindow::BOTTOM_PADDING;

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
        return ScrollWindow::lastOffset($this->totalHeight, $viewportHeight);
    }

    public function clampScrollOffset(int $scrollOffset, int $viewportHeight): int
    {
        return ScrollWindow::clamped($scrollOffset, $this->maxScrollOffset($viewportHeight));
    }
}
