<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;

/**
 * @internal
 */
final class ContentBasis
{
    public static function of(
        Widget $child,
        Constraint $constraint,
        Direction $direction,
        ?int $acrossExtent,
    ): int {
        return match (true) {
            null !== $constraint->basis => $constraint->basis,
            Direction::Horizontal === $direction,
            $constraint->min === $constraint->max,
            null === $acrossExtent || $acrossExtent < 1,
            !$child instanceof MeasureHeight => $constraint->min,
            default => max($constraint->min, $child->measureHeight($acrossExtent)),
        };
    }
}
