<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

/**
 * Store link IDs above the SGR attribute bits; IDs must keep the same URL across frames for diffing to work.
 *
 * @internal
 */
final class Hyperlinks
{
    public const SGR_ATTRIBUTES = 0xFF;
    private const SHIFT = 8;

    /**
     * One entry per distinct URL remains for the process lifetime; avoid unbounded URL sets.
     *
     * @var array<string, int>
     */
    private static array $ids = [];

    /**
     * @var array<int, string>
     */
    private static array $urls = [];

    public static function bitsFor(string $url): int
    {
        if (!isset(self::$ids[$url])) {
            $id = count(self::$ids) + 1;
            self::$ids[$url] = $id;
            self::$urls[$id] = $url;
        }

        return self::$ids[$url] << self::SHIFT;
    }

    public static function urlIn(int $attributes): ?string
    {
        return self::$urls[$attributes >> self::SHIFT] ?? null;
    }
}
