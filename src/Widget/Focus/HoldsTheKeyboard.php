<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Widget\Widget;

/**
 * Let wrappers report focus held by their content without adding another tab stop.
 */
interface HoldsTheKeyboard extends Widget
{
    public function isHeld(): bool;
}
