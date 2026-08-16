<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * Moving the keyboard is a change to the tree, not to the model, so it travels
 * as an effect like any other.
 *
 * @internal
 */
final readonly class FocusCmd extends Cmd
{
    public function __construct(
        private ?ComponentId $target = null,
        private FocusMove $move = FocusMove::To,
    ) {
    }

    /**
     * @param list<ComponentId> $focusables the focusable components, in tree order
     */
    public function applyTo(FocusState $focus, array $focusables): FocusState
    {
        return match ($this->move) {
            FocusMove::To => new FocusState($this->target),
            FocusMove::Next => $focus->next($focusables),
        };
    }
}
