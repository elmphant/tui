<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A focusable that is not always worth stopping at — an empty pane, a detail
 * view with nothing selected yet. It answers for itself, so whatever cycles
 * through components does not have to know which ones are worth a stop.
 */
interface AcceptsFocus extends Focusable
{
    public function acceptsFocus(): bool;
}
