<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;

final class Scrollbar
{
    /**
     * Block glyphs, not box-drawing ones: a scrollbar sitting beside a pane
     * border must not read as a second border.
     */
    public const THUMB = '▐';
    public const TRACK = '▕';

    public static function needsScrollbar(int $totalContent, int $visibleHeight): bool
    {
        return $totalContent > $visibleHeight;
    }

    /**
     * Down the right-hand column of $track, in the one set of colours a
     * scrollbar is drawn in. What the track can show is its own height, so a
     * caller cannot state the two inconsistently.
     */
    public static function renderFor(Frame $frame, Rect $track, int $scrollOffset, int $totalContent, Theme $theme): void
    {
        self::render(
            $frame,
            $track->right() - 1,
            $track->y,
            $track->height,
            $scrollOffset,
            $totalContent,
            $track->height,
            $theme->accent->foregroundValue(),
            $theme->dim->foregroundValue(),
            $theme->base->backgroundValue(),
        );
    }

    public static function render(
        Frame $frame,
        int $x,
        int $y,
        int $height,
        int $scrollOffset,
        int $totalContent,
        int $visibleHeight,
        int $thumbColor,
        int $trackColor,
        int $background = 0,
    ): void {
        if (!self::needsScrollbar($totalContent, $visibleHeight) || $height <= 0) {
            return;
        }

        $thumbSize = max(1, (int)floor($visibleHeight * $height / $totalContent));
        $maxOffset = max(1, $totalContent - $visibleHeight);
        $thumbPosition = (int)floor($scrollOffset / $maxOffset * ($height - $thumbSize));
        $thumbPosition = max(0, min($height - $thumbSize, $thumbPosition));

        $afterThumb = $thumbPosition + $thumbSize;
        $frame->fillRect($x, $y, 1, $thumbPosition, $trackColor, $background, 0, self::TRACK);
        $frame->fillRect($x, $y + $thumbPosition, 1, $thumbSize, $thumbColor, $background, 0, self::THUMB);
        if ($afterThumb < $height) {
            $frame->fillRect($x, $y + $afterThumb, 1, $height - $afterThumb, $trackColor, $background, 0, self::TRACK);
        }
    }
}
