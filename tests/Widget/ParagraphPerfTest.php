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
