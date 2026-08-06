<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

/**
 * The offset a scrolled view is drawn at, in one place so every widget that
 * scrolls works it out the same way.
 *
 * @internal
 */
final class ScrollWindow
{
    /**
     * The last row may be scrolled one line above the floor of the viewport, so
     * it is clear there is nothing under it.
     */
    public const BOTTOM_PADDING = 1;

    public static function lastOffset(int $totalHeight, int $viewportHeight): int
    {
        return $totalHeight <= $viewportHeight
            ? 0
            : $totalHeight - $viewportHeight + self::BOTTOM_PADDING;
    }

    /**
     * An offset held between the first row and the last one it can reach.
     */
    public static function clamped(int $offset, int $maxOffset): int
    {
        return min(max(0, $offset), max(0, $maxOffset));
    }

    /**
     * The offset that keeps $height rows from $top on screen, moving as little
     * as it can.
     */
    public static function showing(int $offset, int $top, int $height, int $viewportHeight, int $maxOffset): int
    {
        $offset = self::clamped($offset, $maxOffset);

        if ($top < $offset) {
            return $top;
        }

        $bottom = $top + $height;

        if ($bottom <= $offset + $viewportHeight) {
            return $offset;
        }

        return $height > $viewportHeight ? $top : $bottom - $viewportHeight;
    }
}
