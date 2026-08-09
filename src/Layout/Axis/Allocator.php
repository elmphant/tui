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
    public static function resolve(array $policies, int $available, ?AxisSpec $spec = null): AxisResult
    {
        $spec ??= new AxisSpec();
        $gaps = max(0, count($policies) - 1) * $spec->gap;
        $available = max(0, $available - $gaps);

        $reserved = 0;
        $totalGrow = 0;

        foreach ($policies as $policy) {
            $reserved += $policy->basis;
            $totalGrow += $policy->grow;
        }

        $spare = max(0, $available - $reserved);
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

        $offsets = [];
        $cursor = 0;
        foreach ($sizes as $size) {
            $offsets[] = $cursor;
            $cursor += $size + $spec->gap;
        }

        return new AxisResult(array_values($sizes), $offsets);
    }
}
