<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Support\Cloneable;

/**
 * The runtime owns focus and passes it to update(); apps request changes with commands.
 */
final readonly class FocusState
{
    use Cloneable;

    private const REMEMBERED = 8;

    /**
     * @param list<ComponentId> $visited   where the keyboard was before, most recent last
     * @param list<ComponentId> $enclosure what the focused component sat inside when last seen, outermost first
     */
    private function __construct(
        private ?ComponentId $focused = null,
        private bool $decided = false,
        private array $visited = [],
        private array $enclosure = [],
    ) {
    }

    public static function undecided(): self
    {
        return new self();
    }

    public static function on(ComponentId $component): self
    {
        return new self($component);
    }

    /**
     * Explicitly unfocused: the runtime must not choose a component.
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
     * Undecided differs from nowhere(): the runtime still needs to choose focus.
     */
    public function isUndecided(): bool
    {
        return null === $this->focused && !$this->decided;
    }

    /**
     * Undecided and nowhere both lack an ID, but only undecided allows automatic focus.
     */
    public function sameAs(self $other): bool
    {
        return $this->focused?->value === $other->focused?->value
            && $this->decided === $other->decided;
    }

    /**
     * Keep focus if reachable; otherwise try a nearby stop, a surviving ancestor, the previous stop, then the first stop.
     * Reachable components include pointer-only panes; tab order does not.
     *
     * @param list<ComponentId>                              $order     where tab stops, in tree order
     * @param list<ComponentId>                              $reachable everywhere the keyboard may be; defaults to $order
     * @param (Closure(ComponentId): list<ComponentId>)|null $enclosing what a component sits inside, outermost first
     */
    public function resolvedAgainst(array $order, ?array $reachable = null, ?Closure $enclosing = null): self
    {
        if (null === $this->focused && $this->decided) {
            return $this;
        }

        if (null !== $this->indexIn($reachable ?? $order)) {
            return null === $this->focused || null === $enclosing ? $this : $this->cloneWith(enclosure: $enclosing($this->focused));
        }

        return $this->remembering($order === [] ? null : $this->landingIn($order, $enclosing), $this->decided);
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
     * @param non-empty-list<ComponentId>                    $order
     * @param (Closure(ComponentId): list<ComponentId>)|null $enclosing
     */
    private function landingIn(array $order, ?Closure $enclosing): ComponentId
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

        $inside = $enclosing === null ? null : $this->landingInside($order, $enclosing);
        if ($inside !== null) {
            return $inside;
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

    /**
     * @param non-empty-list<ComponentId>             $order
     * @param Closure(ComponentId): list<ComponentId> $enclosing
     */
    private function landingInside(array $order, Closure $enclosing): ?ComponentId
    {
        foreach (array_reverse($this->enclosure) as $container) {
            foreach ($order as $stop) {
                foreach ($enclosing($stop) as $around) {
                    if ($around->equals($container)) {
                        return $stop;
                    }
                }
            }

            foreach ($order as $stop) {
                if ($stop->equals($container)) {
                    return $stop;
                }
            }
        }

        return null;
    }

    private function remembering(?ComponentId $id, bool $decided): self
    {
        $left = $this->focused;

        if ($left === null || ($id !== null && $left->equals($id))) {
            return $this->cloneWith(focused: $id, decided: $decided);
        }

        $visited = array_values(array_filter($this->visited, static fn (ComponentId $each): bool => !$each->equals($left)));

        return $this->cloneWith(focused: $id, decided: $decided, visited: array_slice([...$visited, $left], -self::REMEMBERED), enclosure: []);
    }

    /**
     * @param list<ComponentId> $order
     */
    private function movedBy(int $step, array $order): self
    {
        // Keep the current state when there is nowhere to move; clearing it would make focus undecided.
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
