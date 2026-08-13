<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\CharWidth;

/**
 * Where the options of a horizontal row land.
 */
final class OptionRowLayout
{
    public const GAP = 2;

    /**
     * @param list<string> $entries each option as it is drawn, mark included
     *
     * @return list<array{int, int, int}> [x, y, width] for each entry
     */
    public static function placements(array $entries, Rect $area): array
    {
        $x = $area->x;
        $y = $area->y;
        $placements = [];

        foreach ($entries as $entry) {
            $width = CharWidth::stringWidth($entry);

            if ($x > $area->x && $x + $width > $area->right()) {
                $x = $area->x;
                ++$y;
            }

            $placements[] = [$x, $y, $width];
            $x += $width + self::GAP;
        }

        return $placements;
    }

    /**
     * The room the entries want on one line, gaps included. Laid out unbounded
     * so the answer comes from placements() rather than a second copy of its
     * spacing rules.
     *
     * @param list<string> $entries
     */
    public static function width(array $entries): int
    {
        $placements = self::placements($entries, new Rect(0, 0, PHP_INT_MAX, 1));

        if ($placements === []) {
            return 0;
        }

        [$x, , $width] = end($placements);

        return $x + $width;
    }

    /**
     * @param list<string> $entries
     */
    public static function height(array $entries, int $width): int
    {
        $placements = self::placements($entries, new Rect(0, 0, $width, 1));

        return $placements === [] ? 1 : end($placements)[1] + 1;
    }

    /**
     * @param list<string> $entries
     */
    public static function indexAt(array $entries, Rect $area, int $x, int $y): ?int
    {
        foreach (self::placements($entries, $area) as $index => [$left, $row, $width]) {
            if ($y === $row && $x >= $left && $x < $left + $width) {
                return $index;
            }
        }

        return null;
    }
}
