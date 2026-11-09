<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;

/**
 * @internal
 */
final class ScrollWindow
{
    /**
     * Allow the last row to sit one line above the viewport bottom to show that the content ends.
     */
    public const BOTTOM_PADDING = 1;

    public static function lastOffset(int $totalHeight, int $viewportHeight): int
    {
        return $totalHeight <= $viewportHeight
            ? 0
            : $totalHeight - $viewportHeight + self::BOTTOM_PADDING;
    }

    public static function clamped(int $offset, int $maxOffset): int
    {
        return max(0, min($offset, $maxOffset));
    }

    /**
     * @param Closure(int): int $spanHeight lines taken with this much padding
     */
    public static function paddingThatFits(int $padding, Closure $spanHeight, int $viewportHeight): int
    {
        while ($padding > 0 && $spanHeight($padding) > $viewportHeight) {
            --$padding;
        }

        return $padding;
    }

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
