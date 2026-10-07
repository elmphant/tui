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
        private ?ComponentId $target = null,
        public FocusMove $move = FocusMove::To,
    ) {
    }

    /**
     * @param list<ComponentId> $focusables the focusable components, in tree order
     */
    public function applyTo(FocusState $focus, array $focusables): FocusState
    {
        return match ($this->move) {
            FocusMove::To => new FocusState($this->target),
            // Undecided rather than the first id outright: a tree that has just
            // changed may not have grown its components yet, and undecided is
            // what settles on the first once it has.
            FocusMove::First => new FocusState($focusables[0] ?? null),
            FocusMove::Nowhere => FocusState::nowhere(),
            FocusMove::Next => $focus->next($focusables),
            FocusMove::Previous => $focus->previous($focusables),
        };
    }
}
