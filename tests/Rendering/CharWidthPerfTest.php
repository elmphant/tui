<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Grapheme;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharWidthPerfTest extends TestCase
{
    use PerformanceBudget;

    /**
     * A cluster costs a segmentation share plus one memo lookup: 0.11µs idle,
     * 0.26µs seen under full-suite load. Before clusters were segmented in one
     * pass it was 0.36µs idle, so this still fails on that regression.
     */
    private const MAX_MICROSECONDS_PER_CLUSTER = 0.30;

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
    public function measuringClusterDenseTextCostsLittlePerCluster(): void
    {
        self::skipUnderXdebug();

        $mixed = str_repeat("Za\u{017C}\u{00F3}\u{0142}\u{0107} g\u{0119}\u{015B}l\u{0105}, \u{6771}\u{4EAC}, ", 8);
        $clusters = count(Grapheme::split($mixed));

        $measured = self::medianMicroseconds(static fn (): int => CharWidth::stringWidth($mixed));

        self::assertWithinBudget(
            $clusters * self::MAX_MICROSECONDS_PER_CLUSTER,
            $measured,
            sprintf('Measuring %d grapheme clusters', $clusters),
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
