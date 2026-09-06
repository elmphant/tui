<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

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

    public function __construct(
        private ?ComponentId $focused = null,
        private bool $decided = false,
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
     * Whether nobody holding the keyboard is the answer, rather than a gap left
     * before the runtime placed it.
     */
    public function isNowhere(): bool
    {
        return null === $this->focused && $this->decided;
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
     * Whether it may stay is asked of everything it can reach; where it goes when it
     * may not is the first place tab stops. A pointer-only pane is in the first list
     * and not the second, so a press seats the keyboard there and tab still walks past.
     *
     * @param list<ComponentId> $order     where tab stops, in tree order
     * @param list<ComponentId> $reachable everywhere the keyboard may be; defaults to $order
     */
    public function resolvedAgainst(array $order, ?array $reachable = null): self
    {
        if ($order === []) {
            return $this->cloneWith(focused: null);
        }

        if (null === $this->focused && $this->decided) {
            return $this;
        }

        return null === $this->indexIn($reachable ?? $order)
            ? $this->cloneWith(focused: $this->landingIn($order))
            : $this;
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
        for ($ancestor = $this->focused?->parent(); $ancestor !== null; $ancestor = $ancestor->parent()) {
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

        return $order[0];
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

        return $this->cloneWith(focused: $order[
            null === $at
                ? ($step > 0 ? 0 : count($order) - 1)
                : ($at + $step + count($order)) % count($order)
        ]);
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
