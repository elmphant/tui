<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Layout;

use Elmphant\Tui\Layout\ScrollWindow;

/**
 * @internal
 */
final readonly class VerticalStackLayout
{
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
