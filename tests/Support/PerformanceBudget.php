<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Closure;

/**
 * Use the median of individual calls to reduce scheduler noise in container timings.
 */
trait PerformanceBudget
{
    protected const MAX_RATIO = 10.0;

    protected static function skipUnderXdebug(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('Performance budgets are invalid under Xdebug instrumentation');
        }
    }

    protected static function medianMicroseconds(Closure $operation, int $iterations = 200, int $warmups = 20): float
    {
        for ($index = 0; $index < $warmups; ++$index) {
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
     * Run setup outside the timing for each sample so first-use operations start from the same state.
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

    /**
     * A busy machine can slow one side of a ratio; a real regression stays over budget on every attempt.
     *
     * @param Closure(): array{float, string} $measure the ratio, and what to say if it is over budget
     */
    protected static function assertRatioBelow(float $max, Closure $measure): void
    {
        for ($attempt = 1; $attempt < 3; ++$attempt) {
            if ($measure()[0] < $max) {
                self::assertTrue(true);

                return;
            }
        }

        [$ratio, $message] = $measure();
        self::assertLessThan($max, $ratio, $message);
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
