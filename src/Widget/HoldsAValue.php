<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A widget a reader fills in: what it holds is asked of it, so a form never keeps
 * a copy that could fall behind the widget on screen.
 */
interface HoldsAValue extends Widget
{
    /**
     * Text, whether it is on, what was chosen, or every value ticked; null where
     * nothing has been chosen.
     *
     * @return string|bool|list<string>|null
     */
    public function value(): string|bool|array|null;
}
