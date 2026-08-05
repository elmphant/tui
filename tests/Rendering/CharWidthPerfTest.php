<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharWidthPerfTest extends TestCase
{
    use PerformanceBudget;

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
}
