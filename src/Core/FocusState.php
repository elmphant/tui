<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Support\Cloneable;

/**
 * Which component the keyboard reaches, or nobody. One field, so two can never
 * claim it.
 *
 * The runtime owns it and hands it to update(); an app moves it with a command
 * rather than writing to it.
 */
final readonly class FocusState
{
    use Cloneable;

    private const REMEMBERED = 8;

    /**
     * @param list<ComponentId> $visited where the keyboard was before, most recent last
     */
    public function __construct(
        private ?ComponentId $focused = null,
        private bool $decided = false,
        private array $visited = [],
    ) {
    }

    /**
     * Nobody holds the keyboard, and that is an answer rather than a gap the
     * runtime should fill.
     */
    public static function nowhere(): self
    {
        return new self(decided: true);
    }

    public function focused(): ?ComponentId
    {
        return $this->focused;
    }

    /**
     * Whether anything has said where the keyboard is yet. `nowhere()` is an
     * answer; this is the absence of one.
     */
    public function isUndecided(): bool
    {
        return null === $this->focused && !$this->decided;
    }

    /**
     * Naming the same component is not the same state: undecided and nowhere both
     * name none, and settling the one is what stops the runtime filling the other.
     */
    public function sameAs(self $other): bool
    {
        return $this->focused?->value === $other->focused?->value
            && $this->decided === $other->decided;
    }

    /**
     * The keyboard lands somewhere whenever there is anywhere for it to land, so
     * nothing focused means nothing focusable — never a component that left.
     *
     * Whether it may stay is asked of everything it can reach. When it may not, it
     * goes to the nearest stop around it, else back to the last stop it held that is
     * still there (a modal closed), else the first place tab stops — inside it, when
     * it is a strip with stops of its own. A pointer-only pane is in the first list
     * and not the second, so a press seats the keyboard there and tab still walks past.
     *
     * @param list<ComponentId> $order     where tab stops, in tree order
     * @param list<ComponentId> $reachable everywhere the keyboard may be; defaults to $order
     */
    public function resolvedAgainst(array $order, ?array $reachable = null): self
    {
        if (null === $this->focused && $this->decided) {
            return $this;
        }

        if (null !== $this->indexIn($reachable ?? $order)) {
            return $this;
        }

        return $this->remembering($order === [] ? null : $this->landingIn($order), $this->decided);
    }

    public function movedTo(?ComponentId $id): self
    {
        return $this->remembering($id, decided: false);
    }

    /**
     * @param list<ComponentId> $order the focusable components, in tree order
     */
    public function next(array $order): self
    {
        return $this->movedBy(1, $order);
    }

    /**
     * @param list<ComponentId> $order the focusable components, in tree order
     */
    public function previous(array $order): self
    {
        return $this->movedBy(-1, $order);
    }

    /**
     * @param non-empty-list<ComponentId> $order
     */
    private function landingIn(array $order): ComponentId
    {
        for ($ancestor = $this->focused; $ancestor !== null; $ancestor = $ancestor->parent()) {
            foreach ($order as $stop) {
                if ($stop->equals($ancestor)) {
                    return $stop;
                }
            }

            foreach ($order as $stop) {
                if ($stop->isUnder($ancestor)) {
                    return $stop;
                }
            }
        }

        foreach (array_reverse($this->visited) as $earlier) {
            foreach ($order as $stop) {
                if ($stop->equals($earlier)) {
                    return $stop;
                }
            }
        }

        foreach ($order as $stop) {
            if ($stop->isUnder($order[0])) {
                return $stop;
            }
        }

        return $order[0];
    }

    private function remembering(?ComponentId $id, bool $decided): self
    {
        $left = $this->focused;

        if ($left === null || ($id !== null && $left->equals($id))) {
            return $this->cloneWith(focused: $id, decided: $decided);
        }

        $visited = array_values(array_filter($this->visited, static fn (ComponentId $each): bool => !$each->equals($left)));

        return $this->cloneWith(focused: $id, decided: $decided, visited: array_slice([...$visited, $left], -self::REMEMBERED));
    }

    /**
     * @param list<ComponentId> $order
     */
    private function movedBy(int $step, array $order): self
    {
        // Nowhere to go, so nothing moves. Clearing it would leave the state
        // undecided, and a frame drawn on that has no answer to give.
        if ($order === []) {
            return $this;
        }

        $at = $this->indexIn($order);

        return $this->remembering($order[
            null === $at
                ? ($step > 0 ? 0 : count($order) - 1)
                : ($at + $step + count($order)) % count($order)
        ], $this->decided);
    }

    /**
     * @param list<ComponentId> $order
     */
    private function indexIn(array $order): ?int
    {
        if (null === $this->focused) {
            return null;
        }

        foreach ($order as $index => $candidate) {
            if ($candidate->equals($this->focused)) {
                return $index;
            }
        }

        return null;
    }
}
