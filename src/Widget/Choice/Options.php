<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Support\Places;
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
     * @param list<string> $options
     */
    public static function placeOf(?string $value, array $options): ?int
    {
        if ($value === null) {
            return null;
        }

        $place = array_search($value, $options, true);

        return $place === false ? null : $place;
    }

    /**
     * @param list<string> $options
     */
    public static function cursorAfter(?string $under, array $options, int $near): int
    {
        return Places::cursorAfter($under, $options, $near);
    }

    /**
     * @param list<string> $options
     */
    public static function cursorWithin(int $cursor, array $options): int
    {
        return max(0, min($cursor, count($options) - 1));
    }
}
