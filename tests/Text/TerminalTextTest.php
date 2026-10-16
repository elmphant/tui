<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\TerminalText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TerminalTextTest extends TestCase
{
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
