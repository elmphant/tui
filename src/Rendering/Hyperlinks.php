<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

/**
 * A link travels in a cell's attribute bits above the SGR ones, so every write, fill, diff and run split handles it
 * like a colour. The id must mean the same URL in every frame, or the diff would miss a changed link.
 *
 * @internal
 */
final class Hyperlinks
{
    public const SGR_ATTRIBUTES = 0xFF;
    private const SHIFT = 8;

    /**
     * ponytail: one entry per distinct URL for the life of the process; fine for links an app shows, not for millions.
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
