<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\StdinBuffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class StdinBufferTest extends TestCase
{
    #[Test]
    public function plainAsciiEmittedImmediately(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process('abc');
        $this->assertSame(['a', 'b', 'c'], $sequences);
    }

    #[Test]
    public function csiSequenceEmittedAtomically(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process("\x1b[A");
        $this->assertSame(["\x1b[A"], $sequences);
    }

    #[Test]
    public function incompleteCsiBufferedUntilComplete(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process("\x1b[");
        $this->assertSame([], $sequences);

        $buffer->process('A');
        $this->assertSame(["\x1b[A"], $sequences);
    }

    #[Test]
    public function loneEscHeldUntilFlush(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process("\x1b");
        $this->assertSame([], $sequences);

        $buffer->flush();
        $this->assertSame(["\x1b"], $sequences);
    }

    #[Test]
    public function escFollowedByLetterIsAltKey(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process("\x1bx");
        $this->assertSame(["\x1bx"], $sequences);
    }

    #[Test]
    public function ss3SequenceEmittedAtomically(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process("\x1bOP");
        $this->assertSame(["\x1bOP"], $sequences);
    }

    #[Test]
    public function escEscFollowedByCsiSplitsCorrectly(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process("\x1b\x1b[A");
        $this->assertSame(["\x1b", "\x1b[A"], $sequences);
    }

    #[Test]
    public function bracketedPasteEmittedViaPasteCallback(): void
    {
        $sequences = [];
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );
        $buffer->process("\x1b[200~hello world\x1b[201~");
        $this->assertSame([], $sequences);
        $this->assertSame(['hello world'], $pastes);
    }

    #[Test]
    public function highByteMetaNormalized(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );
        $buffer->process(chr(0xF8));
        $this->assertSame(["\x1bx"], $sequences);
    }

    #[Test]
    public function controlCharactersForwardedToOnSequence(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process("\x09");
        $buffer->process("\x0d");
        $buffer->process("\x03");
        $buffer->process("\x11");
        $buffer->process("\x7f");

        $this->assertSame(['09', '0d', '03', '11', '7f'], $sequences);
    }

    #[Test]
    public function controlCharactersMixedWithPrintable(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("a\x09b\x0dc");

        $this->assertSame(['a', "\x09", 'b', "\x0d", 'c'], $sequences);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function utf8SingleChunkProvider(): iterable
    {
        yield '2-byte é' => ['é', ['é']];
        yield '3-byte €' => ['€', ['€']];
        yield '4-byte emoji 🎉' => ['🎉', ['🎉']];
        yield 'mixed ASCII + UTF-8' => ['aéb', ['a', 'é', 'b']];
        yield 'consecutive multi-byte' => ['éè', ['é', 'è']];
        yield '3-byte + ASCII + 4-byte' => ['€x🎉', ['€', 'x', '🎉']];
    }

    /**
     * @param list<string> $expectedSequences
     */
    #[DataProvider('utf8SingleChunkProvider')]
    #[Test]
    public function utf8EmittedAtomically(string $input, array $expectedSequences): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process($input);

        $this->assertSame($expectedSequences, $sequences);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function utf8SplitProvider(): iterable
    {
        yield '2-byte é' => ['é'];
        yield '3-byte €' => ['€'];
        yield '4-byte emoji 🎉' => ['🎉'];
    }

    #[DataProvider('utf8SplitProvider')]
    #[Test]
    public function utf8SplitAcrossChunksBufferedUntilComplete(string $character): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $bytes = $character;
        $lastByte = strlen($bytes) - 1;
        for ($index = 0; $index < $lastByte; ++$index) {
            $buffer->process($bytes[$index]);
            $this->assertSame([], $sequences, "Byte $index of incomplete UTF-8 should be buffered");
        }

        $buffer->process($bytes[$lastByte]);
        $this->assertSame([$character], $sequences);
    }

    #[Test]
    public function utf8IncompleteOnFlushEmitsRawBytes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process("\xC3");
        $this->assertSame([], $sequences);

        $buffer->flush();
        $this->assertSame(['c3'], $sequences);
    }

    #[Test]
    public function overlongLeadBytesMetaConverted(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process(chr(0xC0));
        $this->assertSame(["\x1b\x40"], $sequences);
    }

    #[Test]
    public function escFollowedByNonPrintableByteHeldUntilFlush(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b\x7f");
        $this->assertSame([], $sequences, 'ESC + non-printable should not be emitted as a 2-byte sequence');

        $buffer->flush();
        $this->assertSame(["\x1b\x7f"], $sequences, 'Flush emits the buffered raw bytes');
    }

    #[Test]
    public function utf8BoundaryC2IsLeadNotMeta(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process(chr(0xC2) . chr(0xA0));
        $this->assertSame(["\xC2\xA0"], $sequences);
    }

    #[Test]
    public function utf8BoundaryF4IsLeadNotMeta(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process(chr(0xF4) . chr(0x80) . chr(0x80) . chr(0x80));
        $this->assertSame(["\xF4\x80\x80\x80"], $sequences);
    }

    #[Test]
    public function bracketedPasteSplitAcrossChunksAccumulates(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $buffer->process("\x1b[200~first");
        $this->assertSame([], $pastes, 'Paste not yet complete');

        $buffer->process(" second\x1b[201~");
        $this->assertSame(['first second'], $pastes);
    }

    #[Test]
    public function inputAfterPasteEndProcessedNormally(): void
    {
        $sequences = [];
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $buffer->process("\x1b[200~pasted\x1b[201~abc");

        $this->assertSame(['pasted'], $pastes);
        $this->assertSame(['a', 'b', 'c'], $sequences);
    }

    #[Test]
    public function overlongC0InMultiByteChunkGroupedByUtf8Handler(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process("\xC0\x80a");

        $this->assertSame(['c080', '61'], $sequences);
    }

    #[Test]
    public function singleByteC2NotMetaConverted(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process(chr(0xC2));
        $this->assertSame([], $sequences, '0xC2 should buffer as UTF-8 lead, not meta-convert');

        $buffer->flush();
        $this->assertSame(['c2'], $sequences);
    }

    #[Test]
    public function singleByteF4NotMetaConverted(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process(chr(0xF4));
        $this->assertSame([], $sequences, '0xF4 should buffer as UTF-8 lead, not meta-convert');

        $buffer->flush();
        $this->assertSame(['f4'], $sequences);
    }

    #[Test]
    public function bracketedPasteThreeChunksAccumulates(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $buffer->process("\x1b[200~aa");
        $buffer->process('bb');
        $buffer->process("cc\x1b[201~");

        $this->assertSame(['aabbcc'], $pastes);
    }

    #[Test]
    public function pasteEndMarkerSplitAcrossChunksStillEndsThePaste(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $buffer->process("\x1b[200~hello\x1b[2");
        $this->assertSame([], $pastes, 'Paste not yet complete');

        $buffer->process('01~');
        $this->assertSame(['hello'], $pastes);
    }

    #[Test]
    public function pasteEndMarkerArrivingOneByteAtATimeStillEndsThePaste(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $buffer->process("\x1b[200~drip");

        $marker = "\x1b[201~";
        $lastByte = strlen($marker) - 1;
        for ($index = 0; $index < $lastByte; ++$index) {
            $buffer->process($marker[$index]);
            $this->assertSame([], $pastes, "Byte $index of the split terminator must not complete the paste");
        }

        $buffer->process($marker[$lastByte]);
        $this->assertSame(['drip'], $pastes);
    }

    #[Test]
    public function invalidUtf8LeadF8InChunkForwardedAsSingleByte(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process("\xF8a");

        $this->assertSame(['f8', '61'], $sequences);
    }

    #[Test]
    public function utf8ThreeByteWithLeadEfEmittedAtomically(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\xEF\xBB\xBF");

        $this->assertSame(["\xEF\xBB\xBF"], $sequences);
    }

    #[Test]
    public function utf8FourByteWithLeadF3EmittedAtomically(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\xF3\x80\x80\x80");

        $this->assertSame(["\xF3\x80\x80\x80"], $sequences);
    }

    #[Test]
    public function escEscWithThirdByteLetterEmitsTwoEscapes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process("\x1b\x1bx");

        $this->assertSame(['1b1b', '78'], $sequences);
    }

    #[Test]
    public function escSpaceBoundaryEmittedAsAltSequence(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b ");

        $this->assertSame(["\x1b "], $sequences);
    }

    #[Test]
    public function escTildeBoundaryEmittedAsAltSequence(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b~z");

        $this->assertSame(["\x1b~", 'z'], $sequences);
    }

    #[Test]
    public function ss3WithTrailingDataSplitsCorrectly(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1bOPa");

        $this->assertSame(["\x1bOP", 'a'], $sequences);
    }

    #[Test]
    public function csiBoundaryFinalBytesRecognized(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b[@a");
        $buffer->process("\x1b[~b");

        $this->assertSame(["\x1b[@", 'a', "\x1b[~", 'b'], $sequences);
    }

    #[Test]
    public function escEscWithExactlyThreeBytes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = bin2hex($seq); },
        );

        $buffer->process("\x1b\x1ba");

        $this->assertSame(['1b1b', '61'], $sequences);
    }

    #[Test]
    public function bracketedPasteOverMaxSizeTruncatesEmittedContent(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $oversized = str_repeat('A', StdinBuffer::MAX_PASTE_SIZE + 5000);
        $buffer->process("\x1b[200~" . $oversized . "\x1b[201~");

        $this->assertCount(1, $pastes);
        $this->assertSame(StdinBuffer::MAX_PASTE_SIZE, strlen($pastes[0]));
    }

    #[Test]
    public function unterminatedBracketedPasteBoundsInternalBuffer(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $buffer->process("\x1b[200~" . str_repeat('B', StdinBuffer::MAX_PASTE_SIZE + 10000));

        $this->assertSame([], $pastes, 'No paste should be emitted without a terminator');

        $reflection = new ReflectionProperty(StdinBuffer::class, 'pasteBuffer');
        $internal = $reflection->getValue($buffer);
        $this->assertLessThanOrEqual(StdinBuffer::MAX_PASTE_SIZE, strlen($internal));
    }

    #[Test]
    public function multiChunkPasteOverCapStillTruncatesAtMaxSize(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $halfCap = (int)(StdinBuffer::MAX_PASTE_SIZE / 2);
        $buffer->process("\x1b[200~" . str_repeat('A', $halfCap));
        $buffer->process(str_repeat('A', $halfCap));
        $buffer->process(str_repeat('A', $halfCap) . "\x1b[201~");

        $this->assertCount(1, $pastes);
        $this->assertSame(StdinBuffer::MAX_PASTE_SIZE, strlen($pastes[0]));
    }

    #[Test]
    public function unterminatedCsiOverMaxSequenceSizeIsDiscarded(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b[" . str_repeat('1', StdinBuffer::MAX_SEQUENCE_SIZE + 100));

        $this->assertSame([], $sequences, 'Oversized junk must not reach the parser as keystrokes');

        $reflection = new ReflectionProperty(StdinBuffer::class, 'buffer');
        $this->assertSame('', $reflection->getValue($buffer), 'Internal buffer must be reset after overflow');
    }

    #[Test]
    public function inputAfterSequenceOverflowProcessedNormally(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b[" . str_repeat('1', StdinBuffer::MAX_SEQUENCE_SIZE + 100));
        $buffer->process("\x1b[A");
        $buffer->process('a');

        $this->assertSame(["\x1b[A", 'a'], $sequences);
    }

    #[Test]
    public function multiChunkUnterminatedCsiStaysBounded(): void
    {
        $buffer = new StdinBuffer(onSequence: fn () => null);

        $chunk = str_repeat('1', 512);
        $buffer->process("\x1b[");
        for ($i = 0; $i < 20; ++$i) {
            $buffer->process($chunk);
        }

        $reflection = new ReflectionProperty(StdinBuffer::class, 'buffer');
        $this->assertLessThanOrEqual(
            StdinBuffer::MAX_SEQUENCE_SIZE,
            strlen($reflection->getValue($buffer)),
        );
    }

    #[Test]
    public function incompleteCsiBelowCapSurvivesOverflowCheck(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b[1;2");
        $buffer->process('3R');

        $this->assertSame(["\x1b[1;23R"], $sequences);
    }

    #[Test]
    public function oscReplyTerminatedByBelDoesNotLeakAsKeystrokes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b]11;rgb:1e1e/2e2e/3e3ea\x07a");

        $this->assertSame(['a'], $sequences);
    }

    #[Test]
    public function dcsReplyTerminatedByStDoesNotLeakAsKeystrokes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1bP1+r636f=1b5b313b3243\x1b\\b");

        $this->assertSame(['b'], $sequences);
    }

    #[Test]
    public function oscReplySplitAcrossChunksWithSplitStTerminator(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b]10;rgb:");
        $buffer->process("ffff/ffff/ffff\x1b");
        $this->assertSame([], $sequences, 'Reply with pending terminator must stay buffered');

        $buffer->process('\\c');
        $this->assertSame(['c'], $sequences);
    }

    #[Test]
    public function escKeypressFollowedByOscReplyEmitsOnlyTheEscape(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b\x1b]11;rgb:0000/0000/0000\x07d");

        $this->assertSame(["\x1b", 'd'], $sequences);
    }

    #[Test]
    public function apcReplyTerminatedByStDoesNotLeakAsKeystrokes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b_Gi=1;OK\x1b\\e");

        $this->assertSame(['e'], $sequences);
    }

    #[Test]
    public function unterminatedOscOverMaxSequenceSizeIsDiscarded(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b]52;c;" . str_repeat('Z', StdinBuffer::MAX_SEQUENCE_SIZE + 100));
        $buffer->process('f');

        $this->assertSame(['f'], $sequences);
    }

    #[Test]
    public function incompleteCsiAtExactlyMaxSequenceSizeIsKept(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $filler = str_repeat('1', StdinBuffer::MAX_SEQUENCE_SIZE - 2);
        $buffer->process("\x1b[" . $filler);
        $buffer->process('R');

        $this->assertSame(["\x1b[" . $filler . 'R'], $sequences);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function escFollowedByOpenerProvider(): iterable
    {
        yield 'SS3' => ["\x1b\x1bOP", ["\x1b", "\x1bOP"]];
        yield 'DCS reply' => ["\x1b\x1bP1\$r\x1b\\x", ["\x1b", 'x']];
        yield 'APC reply' => ["\x1b\x1b_Gi=1;OK\x1b\\y", ["\x1b", 'y']];
    }

    /**
     * @param list<string> $expectedSequences
     */
    #[DataProvider('escFollowedByOpenerProvider')]
    #[Test]
    public function escKeypressFollowedByOpenerSplitsCorrectly(string $input, array $expectedSequences): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process($input);

        $this->assertSame($expectedSequences, $sequences);
    }

    #[Test]
    public function emptyOscReplyTerminatedImmediatelyByBelIsSwallowed(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b]\x07");
        $buffer->process('a');

        $this->assertSame(['a'], $sequences);
    }

    #[Test]
    public function dcsReplyWithStAtExactBufferEndIsConsumedImmediately(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1bP1\$r\x1b\\");

        $this->assertSame([], $sequences);

        $reflection = new ReflectionProperty(StdinBuffer::class, 'buffer');
        $this->assertSame('', $reflection->getValue($buffer), 'ST at buffer end must terminate the reply, not leave it pending');
    }

    #[Test]
    public function x10MouseReportFramedWithPayloadBytes(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $report = "\x1b[M" . chr(32 + 0) . chr(32 + 5) . chr(32 + 3);
        $buffer->process($report . 'a');

        self::assertSame([$report, 'a'], $sequences);
    }

    #[Test]
    public function x10MouseReportSplitBeforePayloadStaysBuffered(): void
    {
        $sequences = [];
        $buffer = new StdinBuffer(
            onSequence: function (string $seq) use (&$sequences) { $sequences[] = $seq; },
        );

        $buffer->process("\x1b[M");
        self::assertSame([], $sequences, 'X10 header without payload must stay buffered');

        $buffer->process(chr(32) . chr(33) . chr(34));
        self::assertSame(["\x1b[M" . chr(32) . chr(33) . chr(34)], $sequences);
    }

    #[Test]
    public function multiChunkUnterminatedPasteStaysBoundedAcrossChunks(): void
    {
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: fn () => null,
            onPaste: function (string $content) use (&$pastes) { $pastes[] = $content; },
        );

        $halfCap = (int)(StdinBuffer::MAX_PASTE_SIZE / 2);
        $buffer->process("\x1b[200~" . str_repeat('C', $halfCap));
        $buffer->process(str_repeat('C', $halfCap));
        $buffer->process(str_repeat('C', $halfCap));

        $this->assertSame([], $pastes);

        $reflection = new ReflectionProperty(StdinBuffer::class, 'pasteBuffer');
        $internal = $reflection->getValue($buffer);
        $this->assertSame(StdinBuffer::MAX_PASTE_SIZE, strlen($internal));
    }
}
