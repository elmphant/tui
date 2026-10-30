<?php

declare(strict_types=1);

namespace Elmphant\Tui\Support;

use Closure;
use WeakMap;

/**
 * A mutable cache shared by clones with unchanged content; caching per owner would miss on every keystroke.
 *
 * @internal
 *
 * @template TValue of object
 */
final class KeyedCache
{
    /**
     * Keep both full-width and scrollbar-width measurements, since a widget uses both in one frame.
     */
    private const RETAINED = 2;

    /**
     * Store data in a WeakMap so filling the cache does not change widget equality, and unused caches can be released.
     *
     * @var WeakMap<self<object>, array<int|string, object>>
     */
    private static WeakMap $entries;

    /**
     * @param Closure(): TValue $compute consulted only when $key is not held
     *
     * @return TValue
     */
    public function at(int|string $key, Closure $compute): object
    {
        self::$entries ??= new WeakMap();
        $held = self::$entries[$this] ?? [];

        $existing = $held[$key] ?? null;
        if ($existing !== null) {
            /** @var TValue */
            return $existing;
        }

        if (count($held) >= self::RETAINED) {
            unset($held[array_key_first($held)]);
        }

        $held[$key] = $computed = $compute();
        self::$entries[$this] = $held;

        return $computed;
    }
}
