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
        $gap = self::gapWithin($specification->gap, count($policies), $available);
        $forSlots = max(0, $available - max(0, count($policies) - 1) * $gap);

        $wanted = 0;
        foreach ($policies as $policy) {
            $wanted += $policy->initialSize();
        }

        if ($wanted <= $forSlots) {
            $sizes = self::grown($policies, $forSlots - $wanted);
        } elseif ($specification->overflow === Overflow::ClipTail) {
            $sizes = [];
            $unplaced = $forSlots;
            foreach ($policies as $policy) {
                $size = min($policy->initialSize(), max(0, $unplaced));
                $sizes[] = $size;
                $unplaced -= $size;
            }
        } elseif ($specification->overflow === Overflow::Retain) {
            $sizes = array_map(static fn (ItemPolicy $policy): int => $policy->initialSize(), $policies);
        } else {
            $sizes = self::shrunk($policies, $wanted - $forSlots);

            if ($specification->overflow === Overflow::SqueezeToFloor && array_sum($sizes) > $forSlots) {
                $sizes = self::evenlyWithin($sizes, $policies, $forSlots);
            }
        }

        $unclaimed = max(0, $forSlots - array_sum($sizes));
        [$leading, $between] = self::spread($specification->justification, count($sizes), $unclaimed);

        $offsets = [];
        $cursor = $leading;
        foreach ($sizes as $index => $size) {
            if ($index > 0) {
                $cursor += $gap + ($between[$index - 1] ?? 0);
            }
            $offsets[] = $cursor;
            $cursor += $size;
        }

        return new AxisResult($sizes, $offsets, max(0, array_sum($sizes) - $forSlots));
    }

    /**
     * A gap wider than the axis would place slots outside it, so it gives way
     * to what is actually there.
     */
    private static function gapWithin(int $gap, int $count, int $available): int
    {
        if ($count < 2) {
            return 0;
        }

        return min($gap, intdiv(max(0, $available), $count - 1));
    }

    /**
     * Floors are a request, and this is the case where the axis cannot honour
     * them: every slot is cut in proportion to what it held, so the total fits.
     *
     * @param list<int>        $sizes
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function evenlyWithin(array $sizes, array $policies, int $forSlots): array
    {
        $held = array_sum($sizes);
        $cut = [];
        $placed = 0;

        foreach ($sizes as $index => $size) {
            $share = $held === 0 ? 0 : intdiv($size * $forSlots, $held);
            $share = min($share, $policies[$index]->max);
            $cut[] = $share;
            $placed += $share;
        }

        foreach (array_keys($cut) as $index) {
            if ($placed >= $forSlots) {
                break;
            }
            if ($cut[$index] >= $policies[$index]->max) {
                continue;
            }
            ++$cut[$index];
            ++$placed;
        }

        return $cut;
    }

    /**
     * How many cells sit before the first slot, and how many at each opening
     * between a pair.
     *
     * @return array{int, list<int>}
     */
    private static function spread(Justification $justification, int $count, int $unclaimed): array
    {
        if ($unclaimed === 0 || $count === 0) {
            return [0, []];
        }

        if ($justification === Justification::SpaceEvenly) {
            $openings = self::shared($unclaimed, $count + 1);

            return [$openings[0], array_slice($openings, 1)];
        }

        return match ($justification) {
            Justification::End => [$unclaimed, []],
            Justification::Center => [intdiv($unclaimed, 2), []],
            Justification::SpaceBetween => [0, self::shared($unclaimed, $count - 1)],
            Justification::Start => [0, []],
        };
    }

    /**
     * Cells that do not divide evenly go to the last openings, so the run still
     * ends exactly where the axis does.
     *
     * @return list<int>
     */
    private static function shared(int $amount, int $openings): array
    {
        if ($openings < 1) {
            return [];
        }

        $each = intdiv($amount, $openings);
        $extraFrom = $openings - $amount % $openings;
        $shares = [];

        for ($index = 0; $index < $openings; ++$index) {
            $shares[] = $each + ($index >= $extraFrom ? 1 : 0);
        }

        return $shares;
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
        $sizes = [];
        foreach ($policies as $index => $policy) {
            $sizes[$index] = $policy->initialSize();
        }

        while ($spare > 0) {
            $weight = 0;
            foreach ($policies as $index => $policy) {
                if ($policy->grow > 0 && $sizes[$index] < $policy->max) {
                    $weight += $policy->grow;
                }
            }

            if ($weight === 0) {
                break;
            }

            $handedOut = 0;
            foreach ($policies as $index => $policy) {
                if ($policy->grow > 0 && $sizes[$index] < $policy->max) {
                    $grant = min(intdiv($spare * $policy->grow, $weight), $policy->max - $sizes[$index]);
                    $sizes[$index] += $grant;
                    $handedOut += $grant;
                }
            }

            $spare -= $handedOut;

            if ($handedOut === 0) {
                $spare -= self::nudgeRearmost($sizes, $policies, $spare, true);
                break;
            }
        }

        return array_values($sizes);
    }

    /**
     * The last cell or two, which no share divides evenly, go to the rearmost
     * slot that can still hold them.
     *
     * @param array<int, int>  $sizes
     * @param list<ItemPolicy> $policies
     *
     * @param-out array<int, int> $sizes
     */
    private static function nudgeRearmost(array &$sizes, array $policies, int $cells, bool $growing): int
    {
        $moved = 0;

        foreach (array_reverse(array_keys($sizes)) as $index) {
            if ($cells <= 0) {
                break;
            }

            $policy = $policies[$index];
            $room = $growing
                ? ($policy->grow > 0 ? $policy->max - $sizes[$index] : 0)
                : ($policy->shrink > 0 ? $sizes[$index] - $policy->min : 0);

            $step = min($cells, $room);
            $sizes[$index] += $growing ? $step : -$step;
            $cells -= $step;
            $moved += $step;
        }

        return $moved;
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
        foreach ($policies as $index => $policy) {
            $sizes[$index] = $policy->initialSize();
        }

        while ($deficit > 0) {
            $weight = 0;
            foreach ($policies as $index => $policy) {
                if ($policy->shrink > 0 && $sizes[$index] > $policy->min) {
                    $weight += $policy->shrink;
                }
            }

            if ($weight === 0) {
                break;
            }

            $taken = 0;
            foreach ($policies as $index => $policy) {
                if ($policy->shrink > 0 && $sizes[$index] > $policy->min) {
                    $give = min(intdiv($deficit * $policy->shrink, $weight), $sizes[$index] - $policy->min);
                    $sizes[$index] -= $give;
                    $taken += $give;
                }
            }

            $deficit -= $taken;

            if ($taken === 0) {
                $deficit -= self::nudgeRearmost($sizes, $policies, $deficit, false);
                break;
            }
        }

        return array_values($sizes);
    }
}
