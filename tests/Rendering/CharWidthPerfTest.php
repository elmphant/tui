<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Closure;
use Elmphant\Tui\Rendering\CharWidth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharWidthPerfTest extends TestCase
{
    /**
     * Ratios rather than absolute budgets: both sides run in the same process
     * on the same machine, so speed cancels and only the complexity class
     * remains. A correct implementation lands near 1; walking the whole string
     * as graphemes scores ~50x on this input.
     */
    private const MAX_RATIO = 10.0;

    #[Test]
    public function oneNonAsciiCharacterDoesNotSlowMeasuringTheWholeString(): void
    {
        self::skipUnderXdebug();

        $ascii = str_repeat('workload-000042 ', 300);
        $bulleted = $ascii . '·';

        $plain = self::medianMicroseconds(static fn (): int => CharWidth::stringWidth($ascii));
        $bulleted = self::medianMicroseconds(static fn (): int => CharWidth::stringWidth($bulleted));
        $ratio = $bulleted / $plain;

        self::assertLessThan(
            self::MAX_RATIO,
            $ratio,
            sprintf(
                'One non-ASCII character made stringWidth %.1fx slower (%.1fµs vs %.1fµs). '
                . 'The ASCII fast path must not be all-or-nothing.',
                $ratio,
                $bulleted,
                $plain,
            ),
        );
    }

    #[Test]
    public function oneNonAsciiCharacterDoesNotSlowTruncating(): void
    {
        self::skipUnderXdebug();

        $ascii = str_repeat('workload-000042 ', 300);
        $bulleted = $ascii . '·';

        $plain = self::medianMicroseconds(static fn (): string => CharWidth::truncateToWidth($ascii, 200));
        $bulleted = self::medianMicroseconds(static fn (): string => CharWidth::truncateToWidth($bulleted, 200));
        $ratio = $bulleted / $plain;

        self::assertLessThan(
            self::MAX_RATIO,
            $ratio,
            sprintf(
                'One non-ASCII character made truncateToWidth %.1fx slower (%.1fµs vs %.1fµs).',
                $ratio,
                $bulleted,
                $plain,
            ),
        );
    }

    private static function skipUnderXdebug(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('Performance budgets are invalid under Xdebug instrumentation');
        }
    }

    private static function medianMicroseconds(Closure $operation, int $iterations = 200): float
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
}
