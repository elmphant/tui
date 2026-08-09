<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * Divides one axis among the slots that asked for it.
 *
 * @internal
 */
final class Allocator
{
    /**
     * @param list<ItemPolicy> $policies
     */
    public static function resolve(array $policies, int $available, ?AxisSpecification $specification = null): AxisResult
    {
        $specification ??= new AxisSpecification();
        $gaps = max(0, count($policies) - 1) * $specification->gap;
        $forSlots = max(0, $available - $gaps);

        $wanted = 0;
        foreach ($policies as $policy) {
            $wanted += $policy->basis;
        }

        if ($wanted <= $forSlots) {
            $sizes = self::grown($policies, $forSlots - $wanted);
        } elseif ($specification->overflow === Overflow::ClipTail) {
            $sizes = [];
            $unplaced = $forSlots;
            foreach ($policies as $policy) {
                $size = min($policy->basis, max(0, $unplaced));
                $sizes[] = $size;
                $unplaced -= $size;
            }
        } elseif ($specification->overflow === Overflow::Retain) {
            $sizes = array_map(static fn (ItemPolicy $policy): int => $policy->basis, $policies);
        } else {
            $sizes = self::shrunk($policies, $wanted - $forSlots);

            if ($specification->overflow === Overflow::SqueezeToFloor && array_sum($sizes) > $forSlots) {
                $sizes = self::evenlyWithin($sizes, $forSlots);
            }
        }

        $unclaimed = max(0, $forSlots - array_sum($sizes));
        [$leading, $between] = self::spread($specification->justification, count($sizes), $unclaimed);

        $offsets = [];
        $cursor = $leading;
        foreach ($sizes as $size) {
            $offsets[] = $cursor;
            $cursor += $size + $specification->gap + $between;
        }

        return new AxisResult($sizes, $offsets, max(0, array_sum($sizes) - $forSlots));
    }

    /**
     * Floors are a request, and this is the case where the axis cannot honour
     * them: every slot is cut in proportion to what it held, so the total fits.
     *
     * @param list<int> $sizes
     *
     * @return list<int>
     */
    private static function evenlyWithin(array $sizes, int $forSlots): array
    {
        $held = array_sum($sizes);
        $cut = [];
        $placed = 0;

        foreach ($sizes as $size) {
            $share = $held === 0 ? 0 : intdiv($size * $forSlots, $held);
            $cut[] = $share;
            $placed += $share;
        }

        foreach (array_keys($cut) as $index) {
            if ($placed >= $forSlots) {
                break;
            }
            ++$cut[$index];
            ++$placed;
        }

        return $cut;
    }

    /**
     * How many cells sit before the first slot, and how many between each pair.
     *
     * @return array{int, int}
     */
    private static function spread(Justification $justification, int $count, int $unclaimed): array
    {
        if ($unclaimed === 0 || $count === 0) {
            return [0, 0];
        }

        return match ($justification) {
            Justification::End => [$unclaimed, 0],
            Justification::Center => [intdiv($unclaimed, 2), 0],
            Justification::SpaceBetween => $count === 1 ? [0, 0] : [0, intdiv($unclaimed, $count - 1)],
            Justification::SpaceEvenly => [intdiv($unclaimed, $count + 1), intdiv($unclaimed, $count + 1)],
            Justification::Start => [0, 0],
        };
    }

    /**
     * The spare cells are shared by growth weight and clamped to each ceiling,
     * then whatever integer division left over goes to the rearmost slots that
     * still have room below their ceiling.
     *
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function grown(array $policies, int $spare): array
    {
        $totalGrow = 0;
        foreach ($policies as $policy) {
            $totalGrow += $policy->grow;
        }

        $sizes = [];
        $granted = 0;

        foreach ($policies as $index => $policy) {
            $size = $policy->basis;
            if ($policy->grow > 0 && $totalGrow > 0) {
                $size = min($policy->basis + intdiv($spare * $policy->grow, $totalGrow), $policy->max);
                $granted += $size - $policy->basis;
            }
            $sizes[$index] = $size;
        }

        $leftover = $spare - $granted;
        foreach (array_reverse(array_keys($sizes)) as $index) {
            if ($policies[$index]->grow > 0) {
                $grant = min($leftover, $policies[$index]->max - $sizes[$index]);
                $sizes[$index] += $grant;
                $leftover -= $grant;
            }
        }

        return array_values($sizes);
    }

    /**
     * The mirror of grown(): the missing cells are taken by shrink weight and
     * stopped at each floor, and what floors and rounding leave unpaid is taken
     * from the rearmost slots that are still above their floor.
     *
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function shrunk(array $policies, int $deficit): array
    {
        $sizes = [];
        $totalShrink = 0;

        foreach ($policies as $index => $policy) {
            $sizes[$index] = $policy->basis;
            $totalShrink += $policy->shrink;
        }

        if ($totalShrink === 0) {
            return array_values($sizes);
        }

        $taken = 0;
        foreach ($policies as $index => $policy) {
            if ($policy->shrink > 0) {
                $give = min(intdiv($deficit * $policy->shrink, $totalShrink), $sizes[$index] - $policy->min);
                $sizes[$index] -= $give;
                $taken += $give;
            }
        }

        $unpaid = $deficit - $taken;
        foreach (array_reverse(array_keys($sizes)) as $index) {
            if ($policies[$index]->shrink > 0) {
                $give = min($unpaid, $sizes[$index] - $policies[$index]->min);
                $sizes[$index] -= $give;
                $unpaid -= $give;
            }
        }

        return array_values($sizes);
    }
}
