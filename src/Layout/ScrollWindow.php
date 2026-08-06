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
     * The offset that keeps $height rows from $top on screen, moving as little
     * as it can.
     */
    public static function showing(int $offset, int $top, int $height, int $viewportHeight, int $maxOffset): int
    {
        $offset = min(max(0, $offset), max(0, $maxOffset));

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
