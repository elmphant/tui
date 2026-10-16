<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Layout;

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
            $constraint->min === $constraint->max => $constraint->min,
            // measureHeight() answers rows, so a row asks the child's own width
            // instead of reading a height as one.
            Direction::Horizontal === $direction => $constraint->measuresContent
                ? max($constraint->min, $child->sizing()->columns->intrinsic())
                : $constraint->min,
            null === $acrossExtent || $acrossExtent < 1,
            !$child instanceof MeasureHeight => $constraint->min,
            default => max($constraint->min, $child->measureHeight($acrossExtent)),
        };
    }
}
