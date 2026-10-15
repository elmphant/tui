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
     * A cluster costs a segmentation share plus one memo lookup: 0.10µs idle,
     * 0.26µs seen under full-suite load. Set for a machine under load rather
     * than an idle one — a ceiling a busy CI trips is a coin toss, not a gate.
     * The regression that per-cluster budget was written for is caught by shape
     * instead, in aClusterCostsTheSameInALongStringAsInAShortOne.
     */
    private const MAX_MICROSECONDS_PER_CLUSTER = 0.50;

    /**
     * Measured 1.09-1.11 over three runs, for a string four times as long.
     */
    private const MAX_LENGTH_RATIO = 2.0;

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
    public function wrappingAWordWithoutSpacesCostsInProportionToItsLength(): void
    {
        self::skipUnderXdebug();

        $short = self::medianMicroseconds(static fn (): array => CharWidth::wrapToWidth(str_repeat('é', 500), 1), 20);
        $long = self::medianMicroseconds(static fn (): array => CharWidth::wrapToWidth(str_repeat('é', 2000), 1), 20);

        self::assertLessThan(8.0, $long / $short, 'four times the word must cost about four times as much, not sixteen');
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

    /**
     * What a per-cluster budget cannot say on a loaded machine: a cluster costs
     * the same whatever else is in the string. Segmenting per cluster instead of
     * in one pass makes this quadratic, and the ratio grows with the length.
     */
    #[Test]
    public function aClusterCostsTheSameInALongStringAsInAShortOne(): void
    {
        self::skipUnderXdebug();

        $unit = "Za\u{017C}\u{00F3}\u{0142}\u{0107} g\u{0119}\u{015B}l\u{0105}, \u{6771}\u{4EAC}, ";
        $short = str_repeat($unit, 8);
        $long = str_repeat($unit, 32);

        $perCluster = static function (string $text): float {
            $clusters = count(Grapheme::split($text));

            return self::medianMicroseconds(static fn (): int => CharWidth::stringWidth($text)) / $clusters;
        };

        $ratio = $perCluster($long) / $perCluster($short);

        self::assertLessThan(
            self::MAX_LENGTH_RATIO,
            $ratio,
            sprintf('A string four times as long cost %.2fx as much per cluster', $ratio),
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
