<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\TerminalText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TerminalTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ecma48Sequences(): iterable
    {
        yield 'SGR' => ["\x1b[31mHello\x1b[0m \x1b[1;32mWorld\x1b[0m", 'Hello World'];
        yield 'OSC title with BEL' => ["\x1b]0;my title\x07text", 'text'];
        yield 'OSC with ST' => ["a\x1b]10;rgb:ff/ff/ff\x1b\\b", 'ab'];
        yield 'private mode CSI' => ["a\x1b[?25lb", 'ab'];
        yield 'CSI with intermediate' => ["a\x1b[4 qb", 'ab'];
        yield 'truecolor with colons' => ["\x1b[38:2:255:0:0mred\x1b[m", 'red'];
        yield 'DCS' => ["a\x1bP1\$r0m\x1b\\b", 'ab'];
        yield 'APC' => ["a\x1b_Gi=1\x1b\\b", 'ab'];
        yield 'SS3' => ["a\x1bOPb", 'ab'];
        yield 'lone escape at end' => ["ab\x1b", 'ab'];
        yield 'two-byte escape' => ["a\x1b7b", 'ab'];
    }

    #[DataProvider('ecma48Sequences')]
    #[Test]
    public function strippingAnsiRemovesEveryEcma48SequenceKind(string $input, string $expected): void
    {
        self::assertSame($expected, TerminalText::stripAnsi($input));
    }

    #[Test]
    public function strippingAnsiKeepsControlBytesThatPlainRemoves(): void
    {
        self::assertSame("a\x07b\x00c", TerminalText::stripAnsi("\x1b[1ma\x07b\x00c"));
        self::assertSame('abc', TerminalText::plain("\x1b[1ma\x07b\x00c"));
    }

    #[Test]
    public function plainTextStripsAllEcma48Sequences(): void
    {
        $input = 'safe'
            . "\x1b[31mred\x1b[0m"
            . "\x1b]0;forged title\x07"
            . "\x1bPpayload\x1b\\"
            . "\x1b_hidden\x1b\\"
            . 'end';

        self::assertSame('saferedend', TerminalText::plain($input));
    }

    #[Test]
    public function plainTextStripsRawAndUtf8EncodedControlBytes(): void
    {
        $input = "A\x00B\x1fC\x7fD\x80E\x9fF\u{0080}G\u{009F}H";

        self::assertSame('ABCDEFGH', TerminalText::plain($input));
    }

    #[Test]
    public function plainTextPreservesTabsAndNewlinesForHigherLevelLayout(): void
    {
        self::assertSame("one\ttwo\nthree\rfour five", TerminalText::plain("one\ttwo\nthree\rfour five"));
    }

    #[Test]
    public function singleLineTextRemovesLayoutControlsForTerminalProtocolPayloads(): void
    {
        self::assertSame('onetwothreefour', TerminalText::singleLine("one\ttwo\nthree\rfour"));
    }

    #[Test]
    public function plainTextPreservesUnicodeAndMalformedNonControlBytesVerbatim(): void
    {
        $input = "é€😀\xA0\xC0\xF8";

        self::assertSame($input, TerminalText::plain($input));
    }

    #[Test]
    public function everyRawC1ValueAndBothEncodedBoundariesAreRemoved(): void
    {
        $raw = '';
        for ($byte = 0x80; $byte <= 0x9F; ++$byte) {
            $raw .= chr($byte);
        }

        self::assertSame('beforeafter', TerminalText::plain('before' . $raw . 'after'));
        self::assertSame('AB', TerminalText::plain("A\u{0080}\u{009F}B"));
    }
}
