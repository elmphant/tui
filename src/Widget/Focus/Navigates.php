<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Widget\Focusable;

/**
 * Determine reachability from current state, so filling an empty pane can make it focusable.
 */
interface Navigates extends Focusable
{
    public function navigation(): Navigation;
}
