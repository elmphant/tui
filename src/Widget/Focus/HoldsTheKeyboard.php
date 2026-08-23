<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Widget\Widget;

/**
 * A child that says it is the one its container's keyboard is on, without being a
 * stop the container cycles to. A gutter marking a row, or a decorator around the
 * live field: the part that answers is inside, so the wrapper answers for it.
 */
interface HoldsTheKeyboard extends Widget
{
    public function isHeld(): bool;
}
