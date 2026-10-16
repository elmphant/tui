<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Terminal;

use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\ColorScheme;
use Elmphant\Tui\Terminal\SystemTerminal;
use Elmphant\Tui\Terminal\TerminalException;
use Elmphant\Tui\Terminal\TerminalWriteException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemTerminalTest extends TestCase
{
    /**
     * @var resource
     */
    private $stdin;
    /**
     * @var resource
     */
    private $stdout;

    #[Test]
    public function writeToStdout(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->write('Hello');

        rewind($this->stdout);
        self::assertSame('Hello', stream_get_contents($this->stdout));
    }

    #[Test]
    public function enterAltScreenWritesAnsiSequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->enterAltScreen();

        rewind($this->stdout);
        self::assertSame(Ansi::ENTER_ALT_SCREEN, stream_get_contents($this->stdout));
    }

    #[Test]
    public function leaveAltScreenWritesAnsiSequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->leaveAltScreen();

        rewind($this->stdout);
        self::assertSame(Ansi::LEAVE_ALT_SCREEN, stream_get_contents($this->stdout));
    }

    #[Test]
    public function hideCursorWritesAnsiSequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->hideCursor();

        rewind($this->stdout);
        self::assertSame(Ansi::HIDE_CURSOR, stream_get_contents($this->stdout));
    }

    #[Test]
    public function showCursorWritesAnsiSequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->showCursor();

        rewind($this->stdout);
        self::assertSame(Ansi::SHOW_CURSOR, stream_get_contents($this->stdout));
    }

    #[Test]
    public function enableBracketedPasteWritesMode2004High(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->enableBracketedPaste();

        rewind($this->stdout);
        self::assertSame("\x1b[?2004h", stream_get_contents($this->stdout));
    }

    #[Test]
    public function disableBracketedPasteWritesMode2004Low(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->disableBracketedPaste();

        rewind($this->stdout);
        self::assertSame("\x1b[?2004l", stream_get_contents($this->stdout));
    }

    #[Test]
    public function enableMouseTrackingWritesMultipleSequences(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->enableMouseTracking();

        rewind($this->stdout);
        $expected = Ansi::ENABLE_MOUSE_BASIC . Ansi::ENABLE_MOUSE_MOTION . Ansi::ENABLE_MOUSE_SGR;
        self::assertSame($expected, stream_get_contents($this->stdout));
    }

    #[Test]
    public function disableMouseTrackingWritesMultipleSequences(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->disableMouseTracking();

        rewind($this->stdout);
        $expected = Ansi::DISABLE_MOUSE_SGR . Ansi::DISABLE_MOUSE_MOTION . Ansi::DISABLE_MOUSE_BASIC;
        self::assertSame($expected, stream_get_contents($this->stdout));
    }

    #[Test]
    public function readInputFromStdin(): void
    {
        fwrite($this->stdin, 'input data');
        rewind($this->stdin);

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        self::assertSame('input data', $terminal->readInput());
    }

    #[Test]
    public function readInputAnswersNullOnceStdinIsClosedAndDrained(): void
    {
        [$readEnd, $writeEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($readEnd, false);
        fwrite($writeEnd, 'last');
        fclose($writeEnd);

        $terminal = new SystemTerminal($readEnd, $this->stdout);

        self::assertSame('last', $terminal->readInput());
        self::assertNull($terminal->readInput());
    }

    #[Test]
    public function readInputAnswersEmptyWhileStdinIsOpenButQuiet(): void
    {
        [$readEnd, $writeEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($readEnd, false);

        $terminal = new SystemTerminal($readEnd, $this->stdout);

        self::assertSame('', $terminal->readInput());
        fclose($writeEnd);
    }

    #[Test]
    public function colorProfileDefaultsToAsciiWhenNoEnvVars(): void
    {
        // Clear relevant env vars for this test
        putenv('NO_COLOR');
        putenv('COLORTERM');
        putenv('TERM');

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        self::assertSame(ColorProfile::Ascii, $terminal->colorProfile());
    }

    #[Test]
    public function colorProfileDetectsTrueColor(): void
    {
        putenv('COLORTERM=truecolor');

        // We need a fresh instance to bypass the cached detectedColorProfile
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        self::assertSame(ColorProfile::TrueColor, $terminal->colorProfile());

        putenv('COLORTERM'); // Cleanup
    }

    #[Test]
    public function colorProfileDetectsAnsi256(): void
    {
        putenv('TERM=xterm-256color');

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        self::assertSame(ColorProfile::Ansi256, $terminal->colorProfile());

        putenv('TERM'); // Cleanup
    }

    #[Test]
    public function colorProfileDetectsAnsi(): void
    {
        putenv('TERM=xterm');

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        self::assertSame(ColorProfile::Ansi, $terminal->colorProfile());

        putenv('TERM'); // Cleanup
    }

    #[Test]
    public function colorProfileRespectsNoColor(): void
    {
        putenv('NO_COLOR=1');
        putenv('COLORTERM=truecolor'); // Should be ignored

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        self::assertSame(ColorProfile::Ascii, $terminal->colorProfile());

        putenv('NO_COLOR');
        putenv('COLORTERM'); // Cleanup
    }

    #[Test]
    public function readInputReadsExactly4096BytesWhenMoreAvailable(): void
    {
        fwrite($this->stdin, str_repeat('A', 4097));
        rewind($this->stdin);

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $result = $terminal->readInput();

        self::assertSame(4096, strlen($result));
    }

    #[Test]
    public function readInputReadsAll4096BytesWhenExactlyAvailable(): void
    {
        $data = str_repeat('B', 4096);
        fwrite($this->stdin, $data);
        rewind($this->stdin);

        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $result = $terminal->readInput();

        self::assertSame(4096, strlen($result));
        self::assertSame($data, $result);
    }

    #[Test]
    public function writeReassemblesDataCorrectlyAcrossMultiplePartialWrites(): void
    {
        PartialWriteStream::register();
        PartialWriteStream::$bytesPerWrite = 3;

        $stream = fopen('partialwrite://output', 'w+');
        $terminal = new SystemTerminal($this->stdin, $stream);
        $terminal->write('ABCDEFGHIJ');

        self::assertSame('ABCDEFGHIJ', PartialWriteStream::$written);

        fclose($stream);
        PartialWriteStream::unregister();
    }

    #[Test]
    public function writeAdvancesOffsetCumulativelyAcrossChunks(): void
    {
        PartialWriteStream::register();
        PartialWriteStream::$bytesPerWrite = 2;

        $stream = fopen('partialwrite://output', 'w+');
        $terminal = new SystemTerminal($this->stdin, $stream);
        $terminal->write('ABCDEF');

        self::assertSame('ABCDEF', PartialWriteStream::$written);

        fclose($stream);
        PartialWriteStream::unregister();
    }

    #[Test]
    public function writeThrowsInsteadOfSilentlyDroppingBytesWhenOutputStalls(): void
    {
        PartialWriteStream::register();
        PartialWriteStream::$bytesPerWrite = 0;
        $stream = fopen('partialwrite://output', 'w+');
        $terminal = new SystemTerminal($this->stdin, $stream);

        try {
            $terminal->write('ABC');
            self::fail('A stalled output stream must fail explicitly');
        } catch (TerminalWriteException $error) {
            self::assertSame(0, $error->bytesWritten);
        } finally {
            fclose($stream);
            PartialWriteStream::unregister();
        }
    }

    #[Test]
    public function aReaderThatStopsReadingStallsTheWriteRatherThanBreakingIt(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        stream_set_blocking($pair[0], false);
        $terminal = new SystemTerminal($this->stdin, $pair[0]);

        try {
            $terminal->write(str_repeat('x', 16 * 1024 * 1024));
            self::fail('A reader that never reads must stall the write');
        } catch (TerminalWriteException $error) {
            self::assertTrue($error->stalled);
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    #[Test]
    public function writeRetriesAfterAWritableStreamTemporarilyReturnsZero(): void
    {
        PartialWriteStream::register();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        PartialWriteStream::$castStream = $pair[0];
        PartialWriteStream::$zeroWritesRemaining = 1;

        $stream = fopen('partialwrite://output', 'w+');
        self::assertIsResource($stream);

        try {
            (new SystemTerminal($this->stdin, $stream))->write('ABC');
            self::assertSame('ABC', PartialWriteStream::$written);
        } finally {
            fclose($stream);
            fclose($pair[0]);
            fclose($pair[1]);
            PartialWriteStream::unregister();
        }
    }

    #[Test]
    public function flushThrowsInsteadOfSilentlyIgnoringAnOutputFailure(): void
    {
        PartialWriteStream::register();
        PartialWriteStream::$flushSucceeds = false;
        $stream = fopen('partialwrite://output', 'w+');
        $terminal = new SystemTerminal($this->stdin, $stream);

        try {
            $terminal->flush();
            self::fail('A failed terminal flush must be reported');
        } catch (TerminalException $error) {
            self::assertSame('Unable to flush terminal output', $error->getMessage());
        } finally {
            fclose($stream);
            PartialWriteStream::unregister();
        }
    }

    #[Test]
    public function rawModeRejectsNonTtyInput(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);

        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('requires stdin to be a TTY');

        $terminal->enableRawMode();
    }

    #[Test]
    public function probeSizePreservesLeadingLeftoverBytes(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "abc\x1b[24;80R");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $result = $terminal->probeSize();

        self::assertSame([80, 24], $result);
        self::assertSame('abc', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    /**
     * The probe writes to the same terminal everything else does, so it goes out
     * the one way that survives a full PTY buffer and is counted with the rest.
     */
    #[Test]
    public function theSizeProbeIsWrittenThroughTheTerminalsOwnWrite(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "\x1b[24;80R");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $terminal->probeSize();

        $probe = Ansi::SAVE_CURSOR . Ansi::CURSOR_TO_BOTTOM_RIGHT . Ansi::REPORT_CURSOR_POSITION . Ansi::RESTORE_CURSOR;
        rewind($this->stdout);
        self::assertSame($probe, stream_get_contents($this->stdout));
        self::assertSame(strlen($probe), $terminal->bytesWritten());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function probeSizePreservesTrailingLeftoverBytes(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "\x1b[24;80Rxyz");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $result = $terminal->probeSize();

        self::assertSame([80, 24], $result);
        self::assertSame('xyz', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function probeSizeNoMatchPreservesAllBytes(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, 'garbage');

        $terminal = new SystemTerminal($reader, $this->stdout);
        $result = $terminal->probeSize();

        self::assertNull($result);
        self::assertSame('garbage', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function detectColorSchemePreservesLeftoverBytes(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "pre\033]11;rgb:ffff/ffff/ffff\033\\post");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $result = $terminal->detectColorScheme();

        self::assertNotNull($result);
        self::assertSame('prepost', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function probeSizePreservesBothLeadingAndTrailingLeftovers(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "abc\x1b[24;80Rxyz");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $result = $terminal->probeSize();

        self::assertSame([80, 24], $result);
        self::assertSame('abcxyz', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function probeSizeMatchAccumulatesWithExistingLeftover(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "aaa\x1b[24;80R");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $terminal->probeSize();

        fwrite($writer, "bbb\x1b[48;120R");
        $terminal->probeSize();

        self::assertSame('aaabbb', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function probeSizeNoMatchAccumulatesWithExistingLeftover(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "aaa\x1b[24;80R");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $terminal->probeSize();

        fwrite($writer, 'garbage');
        $terminal->probeSize();

        self::assertSame('aaagarbage', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function leftoversAccumulateAcrossProbeAndColorScheme(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "aaa\x1b[24;80R");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $terminal->probeSize();

        fwrite($writer, "\033]11;rgb:ff/ff/ff\033\\bbb");
        $terminal->detectColorScheme();

        self::assertSame('aaabbb', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function readInputPrependsLeftoversBeforeFreshData(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "aaa\x1b[24;80R");

        $terminal = new SystemTerminal($reader, $this->stdout);
        $terminal->probeSize();

        fwrite($writer, 'fresh');

        self::assertSame('aaafresh', $terminal->readInput());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function detectColorSchemeDarkBackground(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "\033]11;rgb:00/00/00\033\\");

        $terminal = new SystemTerminal($reader, $this->stdout);

        self::assertSame(ColorScheme::Dark, $terminal->detectColorScheme());
        rewind($this->stdout);
        self::assertSame("\033]11;?\033\\", stream_get_contents($this->stdout));

        fclose($reader);
        fclose($writer);
    }

    /**
     * xterm answers with sixteen bits a channel; the high byte is the colour.
     */
    #[Test]
    public function aSixteenBitColourReplyIsReadByItsHighByte(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "\033]11;rgb:1e1e/1e1e/1e1e\033\\");

        self::assertSame(ColorScheme::Dark, (new SystemTerminal($reader, $this->stdout))->detectColorScheme());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function everyWriteCountsTowardsTheBytesWritten(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);

        $terminal->write('abc');
        $terminal->write('de');

        self::assertSame(5, $terminal->bytesWritten());
    }

    #[Test]
    public function eachStalledWriteAddsTheTimeItWaited(): void
    {
        PartialWriteStream::register();
        PartialWriteStream::$bytesPerWrite = 0;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        PartialWriteStream::$castStream = $pair[0];
        $stream = fopen('partialwrite://output', 'w+');
        self::assertIsResource($stream);
        stream_set_blocking($pair[0], false);
        while (@fwrite($pair[0], str_repeat('x', 65536)) > 0) {
        }
        $terminal = new SystemTerminal($this->stdin, $stream);
        $stalledWrite = static function () use ($terminal): void {
            try {
                $terminal->write('A');
            } catch (TerminalWriteException) {
            }
        };

        try {
            $stalledWrite();
            $stalledWrite();

            self::assertGreaterThan(0.15, $terminal->writeWaitSeconds(), 'two waits of 100 ms each');
            self::assertLessThan(1.0, $terminal->writeWaitSeconds());
        } finally {
            fclose($stream);
            fclose($pair[0]);
            fclose($pair[1]);
            PartialWriteStream::unregister();
        }
    }

    /**
     * A terminal may close an OSC reply with BEL instead of the string terminator
     * it was asked with, and answering "no idea" there picks the wrong palette.
     */
    #[Test]
    public function aBellEndsAnOscReplyJustAsTheStringTerminatorDoes(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "\033]11;rgb:00/00/00\x07");

        $terminal = new SystemTerminal($reader, $this->stdout);

        self::assertSame(ColorScheme::Dark, $terminal->detectColorScheme());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function detectColorSchemeLightBackground(): void
    {
        [$reader, $writer] = $this->createNonBlockingSocketPair();
        fwrite($writer, "\033]11;rgb:ff/ff/ff\033\\");

        $terminal = new SystemTerminal($reader, $this->stdout);

        self::assertSame(ColorScheme::Light, $terminal->detectColorScheme());

        fclose($reader);
        fclose($writer);
    }

    #[Test]
    public function detectColorSchemeUsesEachRgbChannelInItsCorrectPosition(): void
    {
        $cases = [
            ['ff', '80', '00', ColorScheme::Light],
            ['80', '00', 'ff', ColorScheme::Dark],
            ['ff', '40', 'ff', ColorScheme::Light],
        ];

        foreach ($cases as [$red, $green, $blue, $expected]) {
            [$reader, $writer] = $this->createNonBlockingSocketPair();
            fwrite($writer, sprintf("\033]11;rgb:%s/%s/%s\033\\", $red, $green, $blue));

            self::assertSame($expected, (new SystemTerminal($reader, $this->stdout))->detectColorScheme());

            fclose($reader);
            fclose($writer);
        }
    }

    #[Test]
    public function queryKittyProtocolWritesQuerySequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->queryKittyProtocol();

        rewind($this->stdout);
        self::assertSame("\x1b[?u", stream_get_contents($this->stdout));
    }

    #[Test]
    public function enableKittyProtocolAsksOnlyForEscapeDisambiguation(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->enableKittyProtocol();

        rewind($this->stdout);
        // Flag 1 alone: asking for event types as well would have the terminal
        // report a release for every press.
        self::assertSame("\x1b[>1u", stream_get_contents($this->stdout));
    }

    #[Test]
    public function disableKittyProtocolWritesDisableSequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->disableKittyProtocol();

        rewind($this->stdout);
        self::assertSame("\x1b[<u", stream_get_contents($this->stdout));
    }

    #[Test]
    public function bellWritesAsciiBellByte(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->bell();

        rewind($this->stdout);
        self::assertSame("\x07", stream_get_contents($this->stdout));
    }

    #[Test]
    public function setTitleWritesOscSequence(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->setTitle('My App');

        rewind($this->stdout);
        self::assertSame("\x1b]0;My App\x07", stream_get_contents($this->stdout));
    }

    #[Test]
    public function setTitleStripsControlCharacters(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->setTitle("Mal\x07icious\x1bTitle\tforged\nline\rreturn");

        rewind($this->stdout);
        self::assertSame("\x1b]0;Maliciousitleforgedlinereturn\x07", stream_get_contents($this->stdout));
    }

    #[Test]
    public function setTitleStripsRawAndUtf8EncodedC1Controls(): void
    {
        $terminal = new SystemTerminal($this->stdin, $this->stdout);
        $terminal->setTitle("A\x80B\x9fC\u{0080}D\u{009F}E");

        rewind($this->stdout);
        self::assertSame("\x1b]0;ABCDE\x07", stream_get_contents($this->stdout));
    }

    /**
     * @return array{resource, resource}
     */
    /**
     * A pty that cannot say how big it is reports zero — docker, CI, tmux control
     * mode. Taken at its word it gives a nought-by-nought screen and the app draws
     * nothing, where the fallback would have shown something.
     */
    #[Test]
    public function zeroColumnsFallBackToTheDefault(): void
    {
        putenv('COLUMNS=0');
        putenv('LINES=0');

        try {
            $size = (new SystemTerminal(fopen('php://memory', 'rb+')))->size();
        } finally {
            putenv('COLUMNS');
            putenv('LINES');
        }

        self::assertSame([80, 24], $size);
    }

    #[Test]
    public function withoutATtyTheSizeComesFromTheEnvironment(): void
    {
        putenv('COLUMNS=120');
        putenv('LINES=abc');

        try {
            $size = (new SystemTerminal(fopen('php://memory', 'rb+')))->size();
        } finally {
            putenv('COLUMNS');
            putenv('LINES');
        }

        self::assertSame([120, 24], $size);
    }

    protected function setUp(): void
    {
        $this->stdin = fopen('php://memory', 'r+');
        $this->stdout = fopen('php://memory', 'r+');
    }

    protected function tearDown(): void
    {
        fclose($this->stdin);
        fclose($this->stdout);
    }

    private function createNonBlockingSocketPair(): array
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($reader, false);

        return [$reader, $writer];
    }
}
