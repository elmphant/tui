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
     * The keyboard lands somewhere whenever there is anywhere for it to land, so
     * nothing focused means nothing focusable — never a component that left.
     *
     * @param list<ComponentId> $order the focusable components, in tree order
     */
    public function resolvedAgainst(array $order): self
    {
        if ($order === []) {
            return $this->cloneWith(focused: null);
        }

        if (null === $this->focused && $this->decided) {
            return $this;
        }

        return null === $this->indexIn($order)
            ? $this->cloneWith(focused: $order[0])
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
     * @param list<ComponentId> $order
     */
    private function movedBy(int $step, array $order): self
    {
        if ($order === []) {
            return $this->cloneWith(focused: null);
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
