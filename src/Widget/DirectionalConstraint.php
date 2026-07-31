<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;

/**
 * Supplies a main-axis constraint that depends on the parent layout direction.
 */
interface DirectionalConstraint
{
    public function constraintFor(Direction $direction): Constraint;
}
