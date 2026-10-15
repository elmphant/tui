<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\AnsiToken;
use Elmphant\Tui\Rendering\AnsiTokenizer;
use Elmphant\Tui\Rendering\AnsiTokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnsiTokenizerTest extends TestCase
{
    #[Test]
    public function plainTextIsASingleTextToken(): void
    {
        self::assertSame(
            [[AnsiTokenType::Text, 'hello world']],
            self::collect('hello world'),
        );
    }

    #[Test]
    public function aCharacterSetDesignationIsOneEscapeToken(): void
    {
        self::assertSame(
            [[AnsiTokenType::Text, 'a'], [AnsiTokenType::Escape, "\x1b(B"], [AnsiTokenType::Text, 'b']],
            self::collect("a\x1b(Bb"),
        );
    }

    #[Test]
    public function everyIntermediateByteBelongsToTheEscapeAndAnUnfinishedOneEndsTheInput(): void
    {
        self::assertSame(
            [[AnsiTokenType::Escape, "\x1b F"], [AnsiTokenType::Escape, "\x1b/A"], [AnsiTokenType::Text, 'x'], [AnsiTokenType::Escape, "\x1b("]],
            self::collect("\x1b F\x1b/Ax\x1b("),
        );
    }

    #[Test]
    public function emptyInputYieldsNoTokens(): void
    {
        self::assertSame([], self::collect(''));
    }

    #[Test]
    public function controlCharactersStayInsideTextTokens(): void
    {
        self::assertSame(
            [[AnsiTokenType::Text, "a\tb\nc"]],
            self::collect("a\tb\nc"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function csiProvider(): iterable
    {
        yield 'SGR color' => ["\x1b[31m"];
        yield 'SGR reset' => ["\x1b[0m"];
        yield 'truecolor with colons' => ["\x1b[38:2:255:0:0m"];
        yield 'private mode' => ["\x1b[?25l"];
        yield 'with intermediate' => ["\x1b[4 q"];
        yield 'cursor move' => ["\x1b[10;20H"];
        yield 'SGR mouse' => ["\x1b[<0;5;3M"];
    }

    #[DataProvider('csiProvider')]
    #[Test]
    public function csiSequencesTokenizeAtomically(string $sequence): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Csi, $sequence],
                [AnsiTokenType::Text, 'b'],
            ],
            self::collect('a' . $sequence . 'b'),
        );
    }

    /**
     * @return iterable<string, array{string, AnsiTokenType}>
     */
    public static function stringSequenceProvider(): iterable
    {
        yield 'OSC with BEL' => ["\x1b]0;title\x07", AnsiTokenType::Osc];
        yield 'OSC with ST' => ["\x1b]10;rgb:ff/ff/ff\x1b\\", AnsiTokenType::Osc];
        yield 'DCS with ST' => ["\x1bP1\$r0m\x1b\\", AnsiTokenType::Dcs];
        yield 'APC with ST' => ["\x1b_Gi=1\x1b\\", AnsiTokenType::Apc];
        yield 'PM with ST' => ["\x1b^payload\x1b\\", AnsiTokenType::Pm];
        yield 'SOS with ST' => ["\x1bXpayload\x1b\\", AnsiTokenType::Sos];
    }

    #[DataProvider('stringSequenceProvider')]
    #[Test]
    public function stringSequencesTokenizeAtomically(string $sequence, AnsiTokenType $expectedType): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [$expectedType, $sequence],
                [AnsiTokenType::Text, 'b'],
            ],
            self::collect('a' . $sequence . 'b'),
        );
    }

    #[Test]
    public function ss3SequenceTokenizesAtomically(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Ss3, "\x1bOP"],
                [AnsiTokenType::Text, 'x'],
            ],
            self::collect("\x1bOPx"),
        );
    }

    #[Test]
    public function twoByteEscapeTokenizesAtomically(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Escape, "\x1b7"],
                [AnsiTokenType::Text, 'x'],
            ],
            self::collect("\x1b7x"),
        );
    }

    #[Test]
    public function loneEscapeAtEndIsAnEscapeToken(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Escape, "\x1b"],
            ],
            self::collect("a\x1b"),
        );
    }

    #[Test]
    public function unterminatedCsiAtEndIsACsiToken(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Csi, "\x1b[12;3"],
            ],
            self::collect("a\x1b[12;3"),
        );
    }

    #[Test]
    public function unterminatedOscAtEndIsAnOscToken(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Osc, "\x1b]0;tit"],
            ],
            self::collect("a\x1b]0;tit"),
        );
    }

    #[Test]
    public function bareCsiIntroducerAtEndIsACsiToken(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Csi, "\x1b["],
            ],
            self::collect("a\x1b["),
        );
    }

    #[Test]
    public function ss3WithSurroundingTextKeepsExactBounds(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Ss3, "\x1bOP"],
                [AnsiTokenType::Text, 'b'],
            ],
            self::collect("a\x1bOPb"),
        );
    }

    #[Test]
    public function csiFinalBytesAtRangeBoundariesAreRecognized(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Csi, "\x1b[2@"],
                [AnsiTokenType::Text, 'b'],
                [AnsiTokenType::Csi, "\x1b[5~"],
                [AnsiTokenType::Text, 'c'],
            ],
            self::collect("a\x1b[2@b\x1b[5~c"),
        );
    }

    #[Test]
    public function oscTerminatedImmediatelyByBel(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Osc, "\x1b]\x07"],
                [AnsiTokenType::Text, 'x'],
            ],
            self::collect("\x1b]\x07x"),
        );
    }

    #[Test]
    public function belInTextBeforeOscDoesNotConfuseTheScanner(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, "x\x07"],
                [AnsiTokenType::Osc, "\x1b]0;t\x07"],
                [AnsiTokenType::Text, 'after'],
            ],
            self::collect("x\x07\x1b]0;t\x07after"),
        );
    }

    #[Test]
    public function unterminatedOscEndingInBareEscapeStaysOneToken(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, 'a'],
                [AnsiTokenType::Osc, "\x1b]t\x1b"],
            ],
            self::collect("a\x1b]t\x1b"),
        );
    }

    #[Test]
    public function backslashInPayloadIsNotAnStTerminator(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Osc, "\x1b]a\\b\x07"],
                [AnsiTokenType::Text, 'x'],
            ],
            self::collect("\x1b]a\\b\x07x"),
        );
    }

    #[Test]
    public function consecutiveSequencesWithoutTextBetween(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Csi, "\x1b[1m"],
                [AnsiTokenType::Csi, "\x1b[31m"],
                [AnsiTokenType::Text, 'bold red'],
                [AnsiTokenType::Csi, "\x1b[0m"],
            ],
            self::collect("\x1b[1m\x1b[31mbold red\x1b[0m"),
        );
    }

    #[Test]
    public function utf8TextPassesThroughUntouched(): void
    {
        self::assertSame(
            [
                [AnsiTokenType::Text, '世界'],
                [AnsiTokenType::Csi, "\x1b[m"],
                [AnsiTokenType::Text, 'é🎉'],
            ],
            self::collect("世界\x1b[m" . 'é🎉'),
        );
    }

    #[Test]
    public function stTerminatorInsideOscPayloadIsNotSplit(): void
    {
        $osc = "\x1b]52;c;aGVsbG8=\x1b\\";
        self::assertSame(
            [
                [AnsiTokenType::Osc, $osc],
                [AnsiTokenType::Text, 'after'],
            ],
            self::collect($osc . 'after'),
        );
    }

    /**
     * @return list<array{AnsiTokenType, string}>
     */
    private static function collect(string $input): array
    {
        $tokens = [];
        foreach (AnsiTokenizer::tokenize($input) as $token) {
            self::assertInstanceOf(AnsiToken::class, $token);
            $tokens[] = [$token->type, $token->content];
        }

        return $tokens;
    }
}
