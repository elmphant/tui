<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use InvalidArgumentException;

/**
 * @internal
 */
final class Options
{
    /**
     * @param list<string> $options
     */
    public static function assertDistinct(array $options, string $owner): void
    {
        $seen = [];

        foreach ($options as $index => $option) {
            if (isset($seen[$option])) {
                throw new InvalidArgumentException(sprintf('%s options must read differently; "%s" is at %d and %d.', $owner, $option, $seen[$option], $index));
            }

            $seen[$option] = $index;
        }
    }

    /**
     * -1 where the value is gone. Options read differently from one another, so a
     * value that is there names one place and only one.
     *
     * @param list<string> $options
     */
    public static function placeOf(?string $value, array $options): int
    {
        if ($value === null) {
            return -1;
        }

        $place = array_search($value, $options, true);

        return $place === false ? -1 : $place;
    }

    /**
     * Unlike a selection, a cursor always lands inside the list.
     *
     * @param list<string> $options
     */
    public static function cursorAfter(?string $under, array $options): int
    {
        return max(0, min(self::placeOf($under, $options), count($options) - 1));
    }
}
