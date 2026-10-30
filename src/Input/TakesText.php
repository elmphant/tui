<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

/**
 * Printable unmodified keys reach this widget before application shortcuts; modified and special keys remain shortcuts.
 */
interface TakesText
{
    public function takesText(): bool;
}
