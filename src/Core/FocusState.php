<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * Which component the keyboard reaches. One field, so two can never claim it.
 *
 * @internal runtime state owned by Program; an app moves focus with a command
 */
final readonly class FocusState
{
    use Cloneable;

    public function __construct(private ?ComponentId $focused = null)
    {
    }

    public function focused(): ?ComponentId
    {
        return $this->focused;
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
