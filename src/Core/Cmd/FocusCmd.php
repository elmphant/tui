<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\FocusState;

/**
 * Moving the keyboard is a change to the tree, not to the model, so it travels
 * as an effect like any other.
 */
final readonly class FocusCmd extends Cmd
{
    public function __construct(
        public ?ComponentId $target = null,
        public FocusMove $move = FocusMove::To,
    ) {
    }

    /**
     * A target named inside a scope is one of that scope's own components.
     *
     * @internal
     */
    public function within(?string $scope): self
    {
        return $scope === null || $this->target === null
            ? $this
            : new self(target: ComponentId::named($scope . '/' . $this->target->value), move: $this->move);
    }

    /**
     * Null when the target is nowhere on screen: a hidden tab, a closed modal.
     *
     * @param list<ComponentId>      $focusables the focusable components, in tree order
     * @param list<ComponentId>|null $reachable  everywhere the keyboard may be, or null from a model with no tree to ask
     */
    public function applyTo(FocusState $focus, array $focusables, ?array $reachable): ?FocusState
    {
        return match ($this->move) {
            FocusMove::To => $reachable === null || $this->isOnScreen([...$focusables, ...$reachable]) ? new FocusState($this->target) : null,
            // Undecided rather than the first id outright: a tree that has just
            // changed may not have grown its components yet, and undecided is
            // what settles on the first once it has.
            FocusMove::First => new FocusState($focusables[0] ?? null),
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
