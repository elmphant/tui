<?php

declare(strict_types=1);

namespace Elmphant\Tui\Support;

/**
 * @internal
 */
final class Places
{
    /**
     * Where the key went after a reload. Of several places holding it, the one
     * nearest where it was; null where it is gone.
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
     * Unlike a selection, a cursor always lands inside the list: on the item that
     * took its place when its own is gone.
     *
     * @param list<string> $keys
     */
    public static function cursorAfter(?string $key, array $keys, int $near): int
    {
        return self::placeOf($key, $keys, $near) ?? max(0, min($near, count($keys) - 1));
    }
}
