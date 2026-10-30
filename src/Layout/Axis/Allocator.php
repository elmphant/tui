<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * @internal
 */
final class Allocator
{
    /**
     * @param list<ItemPolicy> $policies
     */
    public static function resolve(array $policies, int $available, AxisSpecification $specification = new AxisSpecification()): AxisResult
    {
        $gap = self::gapWithin($specification->gap, count($policies), $available);
        $forSlots = max(0, $available - max(0, count($policies) - 1) * $gap);

        $wanted = 0;
        foreach ($policies as $policy) {
            $size = $policy->initialSize();
            $wanted = $size > PHP_INT_MAX - $wanted ? PHP_INT_MAX : $wanted + $size;
        }

        $asked = array_map(static fn (ItemPolicy $policy): int => $policy->initialSize(), $policies);

        $sizes = match (true) {
            $wanted <= $forSlots && $specification->distribution === Distribution::AcrossTheWholeAxis => self::sharedAcross($policies, $forSlots, $specification->remainder),
            $wanted <= $forSlots => self::grown($policies, $forSlots - $wanted, $specification->remainder),
            $specification->overflow === Overflow::ClipTail => self::clippedTail($asked, $forSlots),
            $specification->overflow === Overflow::Retain => $asked,
            // If sizes overflow, divide by proportions because a numeric deficit cannot be computed.
            $wanted === PHP_INT_MAX => self::evenlyWithin($asked, $policies, $forSlots),
            default => self::compressed($policies, $specification->overflow, $wanted - $forSlots, $forSlots),
        };

        $unclaimed = max(0, $forSlots - self::totalOf($sizes));
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

        return new AxisResult($sizes, $offsets, max(0, self::totalOf($sizes) - $forSlots));
    }

    /**
     * @param list<int> $asked
     *
     * @return list<int>
     */
    private static function clippedTail(array $asked, int $forSlots): array
    {
        $sizes = [];
        $unplaced = $forSlots;

        foreach ($asked as $size) {
            $kept = min($size, max(0, $unplaced));
            $sizes[] = $kept;
            $unplaced -= $kept;
        }

        return $sizes;
    }

    /**
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function compressed(array $policies, Overflow $overflow, int $deficit, int $forSlots): array
    {
        $sizes = self::shrunk($policies, $deficit);

        if ($overflow !== Overflow::SqueezeToFloor || self::totalOf($sizes) <= $forSlots) {
            return $sizes;
        }

        $sizes = self::squeezedPastFloors($sizes, $policies, self::totalOf($sizes) - $forSlots);

        return self::totalOf($sizes) > $forSlots
            ? self::evenlyWithin($sizes, $policies, $forSlots)
            : $sizes;
    }

    /**
     * The weights divide the whole axis, and each floor is applied afterwards to
     * whichever slots came out below theirs.
     *
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function sharedAcross(array $policies, int $available, Remainder $remainder): array
    {
        $sizes = array_fill(0, count($policies), 0);
        $claims = $sizes;
        $open = array_filter($policies, static fn (ItemPolicy $policy): bool => $policy->grow > 0);

        if ($open === []) {
            return array_map(static fn (ItemPolicy $policy): int => $policy->initialSize(), $policies);
        }

        // Redistribute space from capped slots among the remaining slots by weight.
        $left = $available;
        do {
            $weight = self::weightOf($open);
            $capped = array_filter($open, static fn (ItemPolicy $policy): bool => intdiv($left * $policy->grow, $weight) >= $policy->max);

            foreach ($capped as $index => $policy) {
                $sizes[$index] = $policy->max;
                $left -= $policy->max;
                unset($open[$index]);
            }
        } while ($capped !== [] && $open !== []);

        $weight = self::weightOf($open);
        $pool = $left;
        foreach ($open as $index => $policy) {
            $exact = $pool * $policy->grow;
            $sizes[$index] = intdiv($exact, $weight);
            $claims[$index] = $exact - $sizes[$index] * $weight;
            $left -= $sizes[$index];
        }

        self::handOutRemainder($remainder, $sizes, $policies, $claims, $left);

        foreach ($policies as $index => $policy) {
            $sizes[$index] = max($sizes[$index], $policy->initialSize());
        }

        return array_values($sizes);
    }

    /**
     * @param array<int, ItemPolicy> $policies
     */
    private static function weightOf(array $policies): int
    {
        $weight = 0;
        foreach ($policies as $policy) {
            $weight += $policy->grow;
        }

        return $weight;
    }

    /**
     * Saturate at PHP_INT_MAX to avoid converting the sum to a float.
     *
     * @param list<int> $sizes
     */
    private static function totalOf(array $sizes): int
    {
        $total = 0;
        foreach ($sizes as $size) {
            $total = $size > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $size;
        }

        return $total;
    }

    private static function gapWithin(int $gap, int $count, int $available): int
    {
        if ($count < 2) {
            return 0;
        }

        return min($gap, intdiv(max(0, $available), $count - 1));
    }

    /**
     * When minimum sizes cannot fit, shrink them according to shrink weights.
     *
     * @param list<int>        $sizes
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function squeezedPastFloors(array $sizes, array $policies, int $deficit): array
    {
        while ($deficit > 0) {
            $weight = 0;
            foreach ($policies as $index => $policy) {
                if ($policy->shrink > 0 && $sizes[$index] > 0) {
                    $weight += (float)$policy->shrink * $sizes[$index];
                }
            }

            if ($weight <= 0.0) {
                return $sizes;
            }

            $taken = 0;
            $roundDeficit = $deficit;
            foreach ($policies as $index => $policy) {
                if ($policy->shrink > 0 && $sizes[$index] > 0) {
                    $give = min((int)floor($roundDeficit * ((float)$policy->shrink * $sizes[$index] / $weight)), $sizes[$index]);
                    $sizes[$index] -= $give;
                    $taken += $give;
                }
            }

            $deficit -= $taken;

            if ($taken === 0) {
                foreach (array_reverse(array_keys($sizes)) as $index) {
                    if ($deficit <= 0) {
                        break;
                    }
                    if ($policies[$index]->shrink > 0 && $sizes[$index] > 0) {
                        --$sizes[$index];
                        --$deficit;
                    }
                }

                return $sizes;
            }
        }

        return $sizes;
    }

    /**
     * If minimum sizes cannot fit, reduce every slot proportionally to fit the axis.
     *
     * @param list<int>        $sizes
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function evenlyWithin(array $sizes, array $policies, int $forSlots): array
    {
        // Divide as floats before multiplying to avoid integer overflow.
        $held = array_sum($sizes);
        $cut = [];
        $placed = 0;

        foreach ($sizes as $index => $size) {
            $share = $held <= 0 ? 0 : (int)floor($size / $held * $forSlots);
            $share = min($share, $policies[$index]->max);
            $cut[] = $share;
            $placed += $share;
        }

        foreach (array_reverse(array_keys($cut)) as $index) {
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
     * Share spare cells by growth weight up to each maximum; give rounding leftovers to the last slots with room.
     *
     * @param list<ItemPolicy> $policies
     *
     * @return list<int>
     */
    private static function grown(array $policies, int $spare, Remainder $remainder): array
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

            if ($weight <= 0.0) {
                break;
            }

            $handedOut = 0;
            $claims = [];
            foreach ($policies as $index => $policy) {
                if ($policy->grow > 0 && $sizes[$index] < $policy->max) {
                    $exact = $spare * $policy->grow;
                    $whole = intdiv($exact, $weight);
                    $grant = min($whole, $policy->max - $sizes[$index]);
                    $sizes[$index] += $grant;
                    $handedOut += $grant;
                    $claims[$index] = $exact - $whole * $weight;
                }
            }

            $spare -= $handedOut;

            if ($handedOut === 0) {
                self::handOutRemainder($remainder, $sizes, $policies, $claims, $spare);
                break;
            }
        }

        return array_values($sizes);
    }

    /**
     * @param array<int, int>  $sizes
     * @param list<ItemPolicy> $policies
     * @param array<int, int>  $claims
     *
     * @param-out array<int, int> $sizes
     */
    private static function handOutRemainder(Remainder $remainder, array &$sizes, array $policies, array $claims, int $cells): void
    {
        if ($remainder === Remainder::StrongestClaim) {
            self::byStrongestClaim($sizes, $policies, $claims, $cells);

            return;
        }

        self::nudgeRearmost($sizes, $policies, $cells, true);
    }

    /**
     * @param array<int, int>  $sizes
     * @param list<ItemPolicy> $policies
     * @param array<int, int>  $claims
     *
     * @param-out array<int, int> $sizes
     */
    private static function byStrongestClaim(array &$sizes, array $policies, array $claims, int $cells): void
    {
        arsort($claims);

        foreach (array_keys($claims) as $index) {
            if ($cells <= 0) {
                break;
            }

            if ($policies[$index]->max - $sizes[$index] < 1) {
                continue;
            }

            ++$sizes[$index];
            --$cells;
        }
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
    private static function nudgeRearmost(array &$sizes, array $policies, int $cells, bool $growing): void
    {
        while ($cells > 0) {
            $movedThisPass = 0;

            foreach (array_reverse(array_keys($sizes)) as $index) {
                if ($cells <= 0) {
                    break;
                }

                $policy = $policies[$index];
                $room = $growing
                    ? ($policy->grow > 0 ? $policy->max - $sizes[$index] : 0)
                    : ($policy->shrink > 0 ? $sizes[$index] - $policy->min : 0);

                if ($room < 1) {
                    continue;
                }

                $sizes[$index] += $growing ? 1 : -1;
                --$cells;
                ++$movedThisPass;
            }

            if ($movedThisPass === 0) {
                break;
            }
        }
    }

    /**
     * Shrink by weight down to each minimum; take rounding leftovers from the last slots still above their minimum.
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
                    $weight += (float)$policy->shrink * $sizes[$index];
                }
            }

            if ($weight <= 0.0) {
                break;
            }

            $taken = 0;
            $roundDeficit = $deficit;
            foreach ($policies as $index => $policy) {
                if ($policy->shrink > 0 && $sizes[$index] > $policy->min) {
                    $give = min((int)floor($roundDeficit * ((float)$policy->shrink * $sizes[$index] / $weight)), $sizes[$index] - $policy->min);
                    $sizes[$index] -= $give;
                    $taken += $give;
                }
            }

            $deficit -= $taken;

            if ($taken === 0) {
                self::nudgeRearmost($sizes, $policies, $deficit, false);
                break;
            }
        }

        return array_values($sizes);
    }
}
