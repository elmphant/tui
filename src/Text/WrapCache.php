<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

use Closure;

/**
 * Cache wrapped text by content so rebuilding widgets does not repeat wrapping.
 *
 * @internal
 */
final class WrapCache
{
    /**
     * Bound by row count so many short entries at multiple widths do not evict each other too soon.
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
