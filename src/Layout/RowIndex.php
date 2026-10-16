<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;
use Elmphant\Tui\Support\KeyedCache;

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

    private ?string $overflowQuestion = null;

    private bool $overflowAnswer = false;

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
     * Whether the rows are taller than $height, stopping at the row that
     * settles it rather than measuring the whole collection for a total the
     * caller does not want.
     *
     * @param Closure(int): int $heightOf
     */
    public function exceeds(int|string $key, int $height, Closure $heightOf): bool
    {
        $question = $key . ':' . $height;

        if ($question === $this->overflowQuestion) {
            return $this->overflowAnswer;
        }

        $total = 0;

        for ($index = 0; $index < $this->rowCount && $total <= $height; ++$index) {
            $total += max(0, $heightOf($index));
        }

        $this->overflowQuestion = $question;

        return $this->overflowAnswer = $total > $height;
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
