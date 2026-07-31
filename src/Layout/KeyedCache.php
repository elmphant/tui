<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;

/**
 * A handful of computed values, kept by the key they were computed for.
 *
 * Intentionally MUTABLE: it is a derived-value cache, not model state. An
 * immutable widget builds one when its content changes and hands the same
 * instance to every clone that keeps that content, so moving a cursor or
 * taking focus reuses the work. Caching keyed on the owner cannot do that —
 * under the immutable-widget contract every keystroke produces a new owner.
 *
 * @internal
 *
 * @template TValue of object
 */
final class KeyedCache
{
    /**
     * A scrollable widget measures at its full width to decide whether a
     * scrollbar is needed, then at the narrowed width to lay out beside it.
     * Both are used in one frame, so both must survive into the next.
     */
    private const RETAINED = 2;

    /**
     * @var array<int|string, TValue>
     */
    private array $entries = [];

    /**
     * @param Closure(): TValue $compute consulted only when $key is not held
     *
     * @return TValue
     */
    public function at(int|string $key, Closure $compute): object
    {
        $existing = $this->entries[$key] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        if (count($this->entries) >= self::RETAINED) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        return $this->entries[$key] = $compute();
    }
}
