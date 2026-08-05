<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Closure;

/**
 * Measurement for the render-path budgets.
 *
 * Every budget is a median of individual timings, never a mean over one long
 * loop: a mean folds each scheduler preemption and every bit of CPU steal into
 * the number, which inside a container inflates it 4-7x and puts the result
 * over budget on a busy machine. Sampling one call at a time and taking the
 * middle sample holds the spread inside 1.7x, so the budgets below are the real
 * per-call cost and fail only on a genuine regression.
 */
trait PerformanceBudget
{
    /**
     * Ratio ceiling for the complexity-class guards, where both sides run in
     * the same process so machine speed cancels out.
     */
    protected const MAX_RATIO = 10.0;

    protected static function skipUnderXdebug(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('Performance budgets are invalid under Xdebug instrumentation');
        }
    }

    protected static function medianMicroseconds(Closure $operation, int $iterations = 200): float
    {
        for ($index = 0; $index < 20; ++$index) {
            $operation();
        }

        $samples = [];
        for ($index = 0; $index < $iterations; ++$index) {
            $start = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $start) / 1_000.0;
        }
        sort($samples);

        return $samples[intdiv($iterations, 2)];
    }

    /**
     * For operations that only cost anything the first time: the setup runs
     * untimed before every sample, so each timed call meets the same state.
     */
    protected static function medianMicrosecondsAfterSetup(Closure $setup, Closure $operation, int $iterations = 200): float
    {
        for ($index = 0; $index < 20; ++$index) {
            $setup();
            $operation();
        }

        $samples = [];
        for ($index = 0; $index < $iterations; ++$index) {
            $setup();
            $start = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $start) / 1_000.0;
        }
        sort($samples);

        return $samples[intdiv($iterations, 2)];
    }

    protected static function assertWithinBudget(float $maxMicroseconds, float $measured, string $what): void
    {
        self::assertLessThan(
            $maxMicroseconds,
            $measured,
            sprintf('%s must be < %.0fµs per call; got %.2fµs', $what, $maxMicroseconds, $measured),
        );
    }
}
