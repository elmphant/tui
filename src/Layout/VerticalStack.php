<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
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
     * @param KeyedCache<VerticalStackLayout> $memo
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
            self::keyFor($children, $width, $height),
            static fn (): VerticalStackLayout => self::compute($children, $width, $height),
        );
    }

    /**
     * Widgets are immutable, so a child that changed is a different object.
     * Identity is therefore enough to tell two child lists apart, and it costs
     * no traversal of their contents.
     *
     * @param list<Widget> $children
     */
    private static function keyFor(array $children, int $width, int $height): string
    {
        $key = $width . ':' . $height;

        foreach ($children as $child) {
            $key .= ':' . spl_object_id($child);
        }

        return $key;
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
        /** @var list<int> $heights */
        $heights = [];
        /** @var list<Constraint> $constraints */
        $constraints = [];
        /** @var array<int, int> $flexWeights */
        $flexWeights = [];
        $fixedTotal = 0;
        $totalFlex = 0;
        $hasWidthAwareChildren = false;

        /** @var list<int> $floors */
        $floors = [];

        foreach ($children as $index => $child) {
            $constraint = $child->constraint();
            $constraints[] = $constraint;
            $isFixed = $constraint->min === $constraint->max;
            $hasWidthAwareChildren = $hasWidthAwareChildren || ($child instanceof MeasureHeight && !$isFixed);

            // Measuring before the split, not after it, is what keeps the total
            // honest: a child grown afterwards spends space the flex siblings
            // were already given, and the stack reports an overflow it does not
            // have.
            $floor = $constraint->min;
            if (!$isFixed && $availableWidth > 0 && $child instanceof MeasureHeight) {
                $floor = max($floor, $child->measureHeight($availableWidth));
            }

            $floors[] = $floor;
            $heights[] = $floor;
            $fixedTotal += $floor;

            if (!$isFixed && !($constraint->min > 0 && $constraint->flex === 0)) {
                $flexWeights[$index] = max(1, $constraint->flex);
                $totalFlex += $flexWeights[$index];
            }
        }

        $policies = [];
        foreach ($constraints as $index => $constraint) {
            $floor = $floors[$index];

            $policies[] = new ItemPolicy(
                basis: $floor,
                min: $floor,
                max: max($floor, $constraint->max),
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
