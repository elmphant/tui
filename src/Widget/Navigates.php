<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A focusable with something to say about how it is reached. Answered on demand,
 * so a pane that fills up becomes reachable without anyone telling it.
 */
interface Navigates extends Focusable
{
    public function navigation(): Navigation;
}
