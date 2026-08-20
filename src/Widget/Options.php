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
     * Where a value sits among the options now, or -1 if it is no longer there.
     * A reader chooses an option, not a row number, so a host that reloads or sorts
     * what it handed in must not leave the number pointing at what moved into that
     * place. Options read differently from one another, so the value names one place.
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
     * Where a cursor lands once the options have changed: on the option it was on
     * if that is still there, and inside the list either way.
     *
     * @param list<string> $options
     */
    public static function cursorAfter(?string $under, array $options): int
    {
        return max(0, min(self::placeOf($under, $options), count($options) - 1));
    }
}
