<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\FocusState;

final readonly class FocusCmd extends Cmd
{
    private function __construct(
        public ?ComponentId $target,
        public FocusMove $move,
        private ?int $child = null,
        private bool $targetIsFull = false,
    ) {
    }

    /**
     * @internal build one with Cmd::focus() and its siblings
     */
    public static function to(ComponentId $target): self
    {
        return new self($target, FocusMove::To);
    }

    /**
     * @internal build one with Cmd::focusChild()
     */
    public static function toChild(int $child): self
    {
        return new self(null, FocusMove::To, $child);
    }

    /**
     * @internal
     */
    public static function first(): self
    {
        return new self(null, FocusMove::First);
    }

    /**
     * @internal
     */
    public static function next(): self
    {
        return new self(null, FocusMove::Next);
    }

    /**
     * @internal
     */
    public static function previous(): self
    {
        return new self(null, FocusMove::Previous);
    }

    /**
     * @internal
     */
    public static function nowhere(): self
    {
        return new self(null, FocusMove::Nowhere);
    }

    /**
     * The component's id is already the full id, also inside a mount, so no scope may add to it.
     *
     * @internal
     */
    public function resolvedUnder(ComponentId $owner): self
    {
        return $this->child === null ? $this : new self(ComponentId::named($owner->value . '/' . $this->child), FocusMove::To, targetIsFull: true);
    }

    /**
     * A target named inside a scope is one of that scope's own components.
     *
     * @internal
     */
    public function within(?string $scope): self
    {
        return $scope === null || $this->target === null || $this->targetIsFull
            ? $this
            : self::to(ComponentId::named($scope . '/' . $this->target->value));
    }

    /**
     * Null when the target is nowhere on screen: a hidden tab, a closed modal.
     *
     * @param list<ComponentId>      $focusables the focusable components, in tree order
     * @param list<ComponentId>|null $reachable  everywhere the keyboard may be, or null from a model with no tree to ask
     */
    public function applyTo(FocusState $focus, array $focusables, ?array $reachable): ?FocusState
    {
        if ($this->child !== null) {
            return null;
        }

        return match ($this->move) {
            FocusMove::To => $reachable === null || $this->isOnScreen([...$focusables, ...$reachable]) ? $focus->movedTo($this->target) : null,
            // Leave focus undecided until the new tree has components to focus.
            FocusMove::First => $focus->movedTo($focusables[0] ?? null),
            FocusMove::Nowhere => FocusState::nowhere(),
            FocusMove::Next => $focus->next($focusables),
            FocusMove::Previous => $focus->previous($focusables),
        };
    }

    /**
     * @param list<ComponentId> $stops
     */
    private function isOnScreen(array $stops): bool
    {
        if ($this->target === null) {
            return true;
        }

        foreach ($stops as $stop) {
            if ($stop->equals($this->target) || $stop->isUnder($this->target)) {
                return true;
            }
        }

        return false;
    }
}
