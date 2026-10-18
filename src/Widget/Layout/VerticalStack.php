<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;

/**
 * Resolves vertical child heights for immutable scroll containers.
 *
 * @internal
 */
final class VerticalStack
{
    /**
     * The memo is keyed by the viewport alone, so a caller hands in a new one
     * whenever the children change. Keying by the children too would cost a walk
     * of the list on every hit, and a plan asks once per child.
     *
     * @param KeyedCache<VerticalStackLayout> $memo     one per child list
     * @param list<Widget>                    $children
     */
    public static function layoutUsing(
        KeyedCache $memo,
        array $children,
        int $availableWidth,
        int $availableHeight,
    ): VerticalStackLayout {
        $width = max(0, $availableWidth);
        $height = max(0, $availableHeight);

        return $memo->at(
            $width . ':' . $height,
            static fn (): VerticalStackLayout => self::compute($children, $width, $height),
        );
    }

    /**
     * @param list<Widget> $children
     */
    private static function compute(array $children, int $availableWidth, int $availableHeight): VerticalStackLayout
    {
        [$heights, $hasWidthAwareChildren] = self::resolve($children, $availableWidth, $availableHeight);
        $totalHeight = array_sum($heights);
        $hasScrollbar = $totalHeight > $availableHeight;
        $childWidth = $hasScrollbar
            ? max(0, $availableWidth - 1)
            : $availableWidth;

        if ($hasScrollbar && $hasWidthAwareChildren && $childWidth !== $availableWidth) {
            [$heights] = self::resolve($children, $childWidth, $availableHeight);
            $totalHeight = array_sum($heights);
        }

        return new VerticalStackLayout($heights, $totalHeight, $childWidth, $hasScrollbar);
    }

    /**
     * @param list<Widget> $children
     *
     * @return array{list<int>, bool}
     */
    private static function resolve(array $children, int $availableWidth, int $availableHeight): array
    {
        /** @var list<Constraint> $constraints */
        $constraints = [];
        $hasWidthAwareChildren = false;

        /** @var list<int> $bases */
        $bases = [];

        foreach ($children as $child) {
            $constraint = $child->sizing()->rows;
            $constraints[] = $constraint;
            $isFixed = $constraint->min === $constraint->max;
            $hasWidthAwareChildren = $hasWidthAwareChildren || ($child instanceof MeasureHeight && !$isFixed);

            // Measuring before the split, not after it, is what keeps the total
            // honest: a child grown afterwards spends space the flex siblings
            // were already given, and the stack reports an overflow it does not
            // have.
            $bases[] = ContentBasis::of($child, $constraint, Direction::Vertical, $availableWidth);
        }

        $policies = [];
        foreach ($constraints as $index => $constraint) {
            $policies[] = new ItemPolicy(
                basis: $bases[$index],
                min: $constraint->min,
                max: $constraint->max,
                grow: $constraint->flex,
            );
        }

        $heights = Allocator::resolve($policies, $availableHeight)->sizes;

        // A stack that scrolls may overrun its viewport, and a child left at no
        // rows cannot be scrolled to, so one that wanted to grow keeps a row.
        foreach ($constraints as $index => $constraint) {
            if ($heights[$index] === 0 && $constraint->flex > 0 && $constraint->max > 0) {
                $heights[$index] = 1;
            }
        }

        return [$heights, $hasWidthAwareChildren];
    }
}
