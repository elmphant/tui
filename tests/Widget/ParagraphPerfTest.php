<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\Prose\Paragraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParagraphPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function aLongTextAnswersItsSizeAgainAsQuicklyAsAShortOne(): void
    {
        self::skipUnderXdebug();

        $short = Paragraph::fromText("one\ntwo");
        $long = Paragraph::fromText(str_repeat("    \"uuid\": \"0123456789abcdef\",\n", 100_000));
        $long->sizing();

        self::assertRatioBelow(self::MAX_RATIO, static function () use ($short, $long): array {
            $shortTime = self::medianMicroseconds(static fn () => $short->sizing(), 50);
            $longTime = self::medianMicroseconds(static fn () => $long->sizing(), 50);
            $ratio = $longTime / max(0.01, $shortTime);

            return [$ratio, sprintf('Asking 100,000 lines for their size again cost %.0fx asking two (%.0fµs vs %.1fµs)', $ratio, $longTime, $shortTime)];
        });
    }

    #[Test]
    public function aLongTextNarrowerThanItsAreaMeasuresItsHeightAsQuicklyAsAShortOne(): void
    {
        self::skipUnderXdebug();

        $short = Paragraph::fromText("one\ntwo");
        $long = Paragraph::fromText(str_repeat("    \"uuid\": \"0123456789abcdef\",\n", 100_000));
        $long->sizing();
        $width = 100;

        self::assertRatioBelow(self::MAX_RATIO, static function () use ($short, $long, &$width): array {
            $shortTime = self::medianMicroseconds(static function () use ($short, &$width): void {
                $short->measureHeight(++$width);
            }, 50);
            $longTime = self::medianMicroseconds(static function () use ($long, &$width): void {
                $long->measureHeight(++$width);
            }, 50);
            $ratio = $longTime / max(0.01, $shortTime);

            return [$ratio, sprintf('Measuring 100,000 short lines at a new width cost %.0fx measuring two (%.0fµs vs %.1fµs)', $ratio, $longTime, $shortTime)];
        });
    }

    #[Test]
    public function aMegabyteOfPlainTextWrapsAtANewWidthWithinAFrame(): void
    {
        self::skipUnderXdebug();

        $text = str_repeat('abcd efgh ', 100_000);
        $paragraph = Paragraph::fromText($text);

        self::assertWithinBudget(
            30_000.0,
            self::medianMicrosecondsAfterSetup(
                static function () use (&$paragraph, $text): void {
                    WrapCache::clear();
                    $paragraph = Paragraph::fromText($text);
                },
                static function () use (&$paragraph): void {
                    $paragraph->measureHeight(60);
                },
                iterations: 9,
            ),
            'measuring a 1 MB line at a new width',
        );
    }

    #[Test]
    public function aMegabyteOfMixedScriptTextWrapsAtANewWidthWithinAFrame(): void
    {
        self::skipUnderXdebug();

        $text = str_repeat('Grüße aus Köln, 東京 und 👩‍👩‍👧 café ', 22_000);
        Paragraph::fromText($text)->measureHeight(59);
        $paragraph = Paragraph::fromText($text);

        self::assertWithinBudget(
            15_000.0,
            self::medianMicrosecondsAfterSetup(
                static function () use (&$paragraph, $text): void {
                    WrapCache::clear();
                    $paragraph = Paragraph::fromText($text);
                },
                static function () use (&$paragraph): void {
                    $paragraph->measureHeight(60);
                },
                iterations: 9,
            ),
            'measuring a 1 MB mixed-script line at a new width',
        );
    }
}
