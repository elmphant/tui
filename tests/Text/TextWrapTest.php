<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextWrapTest extends TestCase
{
    #[Test]
    public function shortTextStaysOnOneLine(): void
    {
        self::assertSame(['Hello world'], TextWrap::wrap('Hello world', 20));
    }

    #[Test]
    public function linesBreakOnWordBoundaries(): void
    {
        self::assertSame(
            ['Flush saved', 'caches with tags'],
            TextWrap::wrap('Flush saved caches with tags', 16),
        );
    }

    #[Test]
    public function aWordWiderThanTheColumnIsHardBroken(): void
    {
        self::assertSame(['abcde', 'fghij', 'k'], TextWrap::wrap('abcdefghijk', 5));
    }

    #[Test]
    public function noLineKeepsTheSpaceItBrokeOn(): void
    {
        foreach (TextWrap::wrap('one two three four', 9) as $line) {
            self::assertSame($line, trim($line), 'No line keeps the break space');
            self::assertLessThanOrEqual(9, CharWidth::stringWidth($line));
        }
    }

    #[Test]
    public function emptyTextOrNoWidthGivesOneEmptyLine(): void
    {
        self::assertSame([''], TextWrap::wrap('', 10));
        self::assertSame([''], TextWrap::wrap('anything', 0));
    }

    #[Test]
    public function wideCharactersTakeTwoColumnsEach(): void
    {
        self::assertSame(
            ["\u{4E16}\u{754C}", "\u{4E16}"],
            TextWrap::wrap("\u{4E16}\u{754C}\u{4E16}", 4),
        );
    }

    #[Test]
    public function aGraphemeWiderThanTheColumnIsMarkedAsCut(): void
    {
        self::assertSame(['…', '…'], TextWrap::wrap("\u{4E16}\u{754C}", 1));
    }

    #[Test]
    public function textThatFitsIsNotEllipsized(): void
    {
        self::assertSame('Hello', TextWrap::ellipsize('Hello', 10));
        self::assertSame('Hello', TextWrap::ellipsize('Hello', 5), 'Text that exactly fits keeps every character');
    }

    #[Test]
    public function cutOffTextEndsInAnEllipsis(): void
    {
        self::assertSame('Hello wor…', TextWrap::ellipsize('Hello world', 10));
        self::assertSame(10, CharWidth::stringWidth(TextWrap::ellipsize('Hello world', 10)));
    }

    #[Test]
    public function noWidthEllipsizesToNothingAndOneColumnToTheMarker(): void
    {
        self::assertSame('', TextWrap::ellipsize('Hello', 0));
        self::assertSame('', TextWrap::ellipsize('Hello', -3));
        self::assertSame('…', TextWrap::ellipsize('Hello', 1));
    }

    #[Test]
    public function ellipsizingKeepsWideCharactersWhole(): void
    {
        $result = TextWrap::ellipsize("\u{4E16}\u{754C}\u{4E16}", 4);

        self::assertSame("\u{4E16}…", $result);
        self::assertLessThanOrEqual(4, CharWidth::stringWidth($result));
    }
}
