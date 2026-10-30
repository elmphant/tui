<?php

declare(strict_types=1);

namespace Elmphant\Tui\Support;

/**
 * @internal
 */
final class Places
{
    /**
     * Match by key after reload; prefer the nearest duplicate and return null if the key disappeared.
     *
     * @param list<string> $keys
     */
    public static function placeOf(?string $key, array $keys, int $near): ?int
    {
        if ($key === null) {
            return null;
        }

        $nearest = null;
        foreach ($keys as $place => $candidate) {
            if ($candidate === $key && ($nearest === null || abs($place - $near) < abs($nearest - $near))) {
                $nearest = $place;
            }
        }

        return $nearest;
    }

    /**
     * If the cursor's item disappears, keep the cursor on its replacement rather than clearing it.
     *
     * @param list<string> $keys
     */
    public static function cursorAfter(?string $key, array $keys, int $near): int
    {
        return self::placeOf($key, $keys, $near) ?? max(0, min($near, count($keys) - 1));
    }
}
