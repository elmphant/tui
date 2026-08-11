<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Widget\Widget;

/**
 * Resolved height and scrollbar geometry for one vertical stack viewport.
 *
 * @internal
 */
final readonly class VerticalStackLayout
{
    /**
     * @param list<int>    $heights
     * @param list<Widget> $children the layout is keyed by their identity, so it
     *                               keeps them alive to stop those identifiers
     *                               being handed to anything else
     */
    public function __construct(
        public array $heights,
        public int $totalHeight,
        public int $childWidth,
        public bool $hasScrollbar,
        public array $children = [],
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
