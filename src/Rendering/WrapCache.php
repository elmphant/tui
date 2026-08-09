<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Closure;

/**
 * Wrapped text kept by content, so a widget rebuilt each frame does not re-wrap.
 *
 * Measured on a 50 KB line: wrapping 10 ms, keying it 0.02 ms.
 *
 * @internal
 */
final class WrapCache
{
    /**
     * Bounded by rows, not entries: 85 children × 2 widths thrashed a 128-entry budget.
     */
    private const ROWS = 50_000;

    /**
     * @var array<string, list<mixed>>
     */
    private static array $entries = [];

    private static int $rows = 0;

    private static int $wraps = 0;

    /**
     * @param Closure(): list<mixed> $wrap
     *
     * @return list<mixed>
     */
    public static function remember(string $key, Closure $wrap): array
    {
        if (isset(self::$entries[$key])) {
            return self::$entries[$key];
        }

        ++self::$wraps;
        $wrapped = $wrap();
        self::$entries[$key] = $wrapped;
        self::$rows += count($wrapped);

        while (self::$rows > self::ROWS && count(self::$entries) > 1) {
            $oldest = array_key_first(self::$entries);
            self::$rows -= count(self::$entries[$oldest]);
            unset(self::$entries[$oldest]);
        }

        return $wrapped;
    }

    /**
     * How many wraps ran since the last clear, so a caller can show it never re-wraps.
     */
    public static function wraps(): int
    {
        return self::$wraps;
    }

    public static function clear(): void
    {
        self::$entries = [];
        self::$rows = 0;
        self::$wraps = 0;
    }
}
