<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;

/**
 * Row measurements for one collection, kept per layout key.
 *
 * This class is intentionally MUTABLE — it is a derived-value cache, not model
 * state, the same category as a process handle. An immutable widget builds one
 * when its rows change and hands the same instance to every clone that keeps
 * those rows, so moving a cursor or taking focus reuses the measurements
 * instead of recomputing them. Owner-keyed caching cannot do that: under the
 * immutable-widget contract every keystroke produces a new owner.
 *
 * The key is whatever the rows were measured against — a width for a list, the
 * column widths for a table.
 *
 * @internal
 */
final class RowIndex
{
    /**
     * @var KeyedCache<RowExtents>
     */
    private readonly KeyedCache $byKey;

    private function __construct(private readonly int $rowCount)
    {
        $this->byKey = self::newCache();
    }

    public static function of(int $rowCount): self
    {
        return new self(max(0, $rowCount));
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * @param Closure(int): int $heightOf consulted only when $key is not held
     */
    public function at(int|string $key, Closure $heightOf): RowExtents
    {
        $rowCount = $this->rowCount;

        return $this->byKey->at($key, static fn (): RowExtents => RowExtents::measure($rowCount, $heightOf));
    }

    /**
     * @return KeyedCache<RowExtents>
     */
    private static function newCache(): KeyedCache
    {
        /** @var KeyedCache<RowExtents> $cache */
        $cache = new KeyedCache();

        return $cache;
    }
}
