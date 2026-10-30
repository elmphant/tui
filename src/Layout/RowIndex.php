<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;
use Elmphant\Tui\Support\KeyedCache;

/**
 * Mutable measurements shared by clones with the same rows; a new owner per keystroke would defeat owner-based caching.
 * Keys describe the measurement layout, such as list width or table column widths.
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
        return new self($rowCount);
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
     * Stop measuring as soon as the rows exceed $height.
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
