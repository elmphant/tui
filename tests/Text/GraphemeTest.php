<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Text\TerminalText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphemeTest extends TestCase
{
    #[Test]
    public function combiningMarkStaysWithBase(): void
    {
        $this->assertSame(["e\u{0301}"], Grapheme::split("e\u{0301}"));
    }

    #[Test]
    public function emojiZwjSequenceIsSingleGrapheme(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
        $this->assertCount(1, Grapheme::split($family));
    }

    #[Test]
    public function flagIsSingleGrapheme(): void
    {
        $this->assertCount(1, Grapheme::split("\u{1F1FA}\u{1F1F8}"));
    }

    #[Test]
    public function mixedAsciiAndEmoji(): void
    {
        $this->assertSame(['H', 'i', "\u{1F44B}"], Grapheme::split("Hi\u{1F44B}"));
    }

    #[Test]
    public function firstTakesTheLeadingClusterWhole(): void
    {
        $this->assertSame("\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}", Grapheme::first("\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}tail"));
    }

    /**
     * TerminalText::plain() keeps such a byte by design, so splitting must too.
     */
    #[Test]
    public function aBrokenByteLeavesTheClustersAroundItWhole(): void
    {
        self::assertSame(["e\u{301}", "\xff", "☺\u{FE0F}"], Grapheme::split("e\u{301}\xff☺\u{FE0F}"));
    }

    #[Test]
    public function aByteThatFormsNoCharacterIsKeptAsItsOwnCluster(): void
    {
        self::assertSame(['a', "\xFF", 'b'], Grapheme::split("a\xFFb"));
        self::assertSame(["\xFF"], Grapheme::split("\xFF"));
    }

    #[Test]
    public function aCharacterCutShortIsOneCluster(): void
    {
        self::assertSame(['a', "\xe2\x82", 'b'], Grapheme::split("a\xe2\x82b"));
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function malformedSequences(): iterable
    {
        yield 'a character cut short' => ["a\xe2\x82b", ['a', "\xe2\x82", 'b'], "a\u{FFFD}b"];
        yield 'an emoji cut short' => ["a\xf0\x9f\x98b", ['a', "\xf0\x9f\x98", 'b'], "a\u{FFFD}b"];
        yield 'an overlong lead' => ["a\xc0\xafb", ['a', "\xc0", "\xaf", 'b'], "a\u{FFFD}\u{FFFD}b"];
        yield 'an overlong three-byte form' => ["a\xe0\x80\xafb", ['a', "\xe0", "\x80", "\xaf", 'b'], "a\u{FFFD}\u{FFFD}b"];
        yield 'a surrogate' => ["a\xed\xa0\x80b", ['a', "\xed", "\xa0", "\x80", 'b'], "a\u{FFFD}\u{FFFD}b"];
        yield 'past the last code point' => ["a\xf4\x90\x80\x80b", ['a', "\xf4", "\x90", "\x80", "\x80", 'b'], "a\u{FFFD}b"];
    }

    /**
     * A stray byte in 0x80–0x9F would be a C1 control, so plain() drops it and it takes no column.
     *
     * @param list<string> $clusters
     */
    #[Test]
    #[DataProvider('malformedSequences')]
    public function eachMalformedSequenceIsOneClusterOneColumnAndOneReplacementCharacter(string $text, array $clusters, string $plain): void
    {
        self::assertSame($clusters, Grapheme::split($text));
        self::assertSame($plain, TerminalText::plain($text));
        self::assertSame(CharWidth::stringWidth($plain), CharWidth::stringWidth($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function strings(): iterable
    {
        yield 'ascii' => ['abc'];
        yield 'valid multibyte' => ["caf\u{00E9}"];
        yield 'combining mark' => ["e\u{0301}x"];
        yield 'zwj sequence' => ["\u{1F468}\u{200D}\u{1F469}tail"];
        yield 'leads with a bad byte' => ["\xFFab"];
        yield 'leads with a continuation byte' => ["\x80ab"];
        yield 'leads with a truncated sequence' => ["\xC3ab"];
        yield 'valid multibyte then a bad byte' => ["\u{00E4}\xFF"];
        yield 'bad byte alone' => ["\xFF"];
        yield 'empty' => [''];
    }

    /**
     * An optimisation of split()[0], so it must never answer differently.
     */
    #[Test]
    #[DataProvider('strings')]
    public function firstNamesWhateverSplittingWouldPutFirst(string $input): void
    {
        self::assertSame(Grapheme::split($input)[0] ?? '', Grapheme::first($input));
    }
}
