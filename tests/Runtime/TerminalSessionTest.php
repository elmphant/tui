<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\InlineScreen;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Runtime\FullScreenSurface;
use Elmphant\Tui\Runtime\InlineSurface;
use Elmphant\Tui\Runtime\ShutdownRestorer;
use Elmphant\Tui\Runtime\TerminalSession;
use Elmphant\Tui\Runtime\TerminalSessionState;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\Terminal;
use Elmphant\Tui\Testing\VirtualTerminal;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

final class TerminalSessionTest extends TestCase
{
    #[Test]
    public function aSessionCanOnlyBeStartedOnce(): void
    {
        $session = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        $session->start();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Terminal session can only be started once');

        $session->start();
    }

    #[Test]
    public function suspendingAndResumingOutOfTurnDoesNothing(): void
    {
        $session = new TerminalSession(new VirtualTerminal(80, 24), false, false);

        $session->suspend();
        self::assertSame(TerminalSessionState::New, $session->state(), 'A session that never started stays new');

        $session->resume();
        self::assertSame(TerminalSessionState::New, $session->state(), 'And resuming does not start it');

        $session->start();
        $session->suspend();
        self::assertSame(TerminalSessionState::Suspended, $session->state());

        $session->suspend();
        self::assertSame(TerminalSessionState::Suspended, $session->state(), 'Suspending twice is a no-op');
    }

    #[Test]
    public function unregisterBeforeAnyRegistrationIsANoop(): void
    {
        $sessions = new ReflectionProperty(ShutdownRestorer::class, 'sessions');
        $sessions->setValue(null, null);
        $session = new TerminalSession(new VirtualTerminal(80, 24), false, false);

        ShutdownRestorer::unregister($session);

        self::assertSame(0, ShutdownRestorer::activeCount());
    }

    #[Test]
    public function itTracksTheCompleteModeLifecycle(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $session = new TerminalSession($terminal, true, true);

        self::assertSame(TerminalSessionState::New, $session->state());

        $session->start();

        self::assertSame(TerminalSessionState::Active, $session->state());
        self::assertTrue($terminal->isRawModeEnabled());
        self::assertTrue($terminal->isBracketedPasteEnabled());
        self::assertFalse($terminal->isCursorVisible());
        self::assertTrue($terminal->isAltScreenEnabled());
        self::assertTrue($terminal->isMouseTrackingEnabled());

        $session->enableKittyProtocol();
        $session->suspend();

        self::assertSame(TerminalSessionState::Suspended, $session->state());
        self::assertFalse($terminal->isRawModeEnabled());
        self::assertFalse($terminal->isBracketedPasteEnabled());
        self::assertTrue($terminal->isCursorVisible());
        self::assertFalse($terminal->isAltScreenEnabled());
        self::assertFalse($terminal->isMouseTrackingEnabled());
        self::assertFalse($terminal->isKittyEnabled());

        $session->resume();

        self::assertSame(TerminalSessionState::Active, $session->state());
        self::assertTrue($terminal->isRawModeEnabled());
        self::assertTrue($terminal->isBracketedPasteEnabled());
        self::assertFalse($terminal->isCursorVisible());
        self::assertTrue($terminal->isAltScreenEnabled());
        self::assertTrue($terminal->isMouseTrackingEnabled());
        self::assertTrue($terminal->isKittyEnabled());

        $session->close();

        self::assertSame(TerminalSessionState::Closed, $session->state());
        self::assertFalse($terminal->isRawModeEnabled());
        self::assertFalse($terminal->isBracketedPasteEnabled());
        self::assertTrue($terminal->isCursorVisible());
        self::assertFalse($terminal->isAltScreenEnabled());
        self::assertFalse($terminal->isMouseTrackingEnabled());
        self::assertFalse($terminal->isKittyEnabled());
    }

    #[Test]
    public function failedActivationRestoresOnlyModesThatWereEnabled(): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->expects(self::once())->method('enableRawMode');
        $terminal->expects(self::once())->method('enableBracketedPaste');
        $terminal->expects(self::once())->method('hideCursor');
        $terminal->expects(self::once())->method('enterAltScreen')
            ->willThrowException(new RuntimeException('alt screen failed'));
        $terminal->expects(self::never())->method('enableMouseTracking');

        $terminal->expects(self::once())->method('disableRawMode');
        $terminal->expects(self::once())->method('disableBracketedPaste');
        $terminal->expects(self::once())->method('showCursor');
        $terminal->expects(self::never())->method('leaveAltScreen');
        $terminal->expects(self::never())->method('disableMouseTracking');

        $session = new TerminalSession($terminal, true, true);

        try {
            $session->start();
            self::fail('Expected terminal activation to fail');
        } catch (RuntimeException $error) {
            self::assertSame('alt screen failed', $error->getMessage());
        }

        self::assertSame(TerminalSessionState::Closed, $session->state());
    }

    #[Test]
    public function aResumeThatCannotReactivateClosesTheSessionAndSaysWhy(): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->method('enableRawMode')->willReturnOnConsecutiveCalls(
            null,
            self::throwException(new RuntimeException('stty failed')),
        );
        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $session->suspend();

        try {
            $session->resume();
            self::fail('Expected the resume to fail');
        } catch (RuntimeException $error) {
            self::assertSame('stty failed', $error->getMessage());
        }

        self::assertSame(TerminalSessionState::Closed, $session->state());
    }

    #[Test]
    public function closeIsIdempotentAndLeavesTheCursorWhereTheSurfaceSays(): void
    {
        $terminal = new VirtualTerminal(80, 42);
        $surface = new FullScreenSurface($terminal, new Screen($terminal), false);
        $session = new TerminalSession($terminal, false, false, $surface);
        $session->start();

        $session->close();
        $firstOutput = $terminal->output();
        $session->close();

        self::assertSame($firstOutput, $terminal->output());
        self::assertStringContainsString(Ansi::RESET_STYLE, $firstOutput);
        self::assertStringContainsString(Ansi::SHOW_CURSOR, $firstOutput);
        self::assertStringContainsString(Ansi::cursorToRowStart(41) . "\n", $firstOutput);
    }

    #[Test]
    public function theSharedRestorerReleasesCompletedSessionsAndKeepsActiveSessionsUntilRestored(): void
    {
        $baseline = ShutdownRestorer::activeCount();
        $completed = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        $completed->start();

        self::assertSame($baseline + 1, ShutdownRestorer::activeCount());

        $completed->close();
        self::assertSame($baseline, ShutdownRestorer::activeCount());

        $abandoned = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        $abandoned->start();
        $weakReference = WeakReference::create($abandoned);
        unset($abandoned);
        gc_collect_cycles();

        self::assertNotNull($weakReference->get());
        self::assertSame($baseline + 1, ShutdownRestorer::activeCount());

        $retained = $weakReference->get();
        self::assertInstanceOf(TerminalSession::class, $retained);
        $retained->close();
        unset($retained);
        gc_collect_cycles();

        self::assertNull($weakReference->get());
        self::assertSame($baseline, ShutdownRestorer::activeCount());
    }

    #[Test]
    public function closeOnANeverStartedSessionMarksItClosed(): void
    {
        $session = new TerminalSession(new VirtualTerminal(80, 24), false, false);

        $session->close();

        self::assertSame(TerminalSessionState::Closed, $session->state());
    }

    #[Test]
    public function redundantCloseReturnsEarlyAndKeepsExternalRestorerRegistrations(): void
    {
        $baseline = ShutdownRestorer::activeCount();
        $session = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        $session->start();
        $session->close();

        self::assertSame($baseline, ShutdownRestorer::activeCount());

        ShutdownRestorer::register($session);
        try {
            $session->close();

            self::assertSame($baseline + 1, ShutdownRestorer::activeCount());
        } finally {
            ShutdownRestorer::unregister($session);
        }
    }

    #[Test]
    public function closeRetriesModesThatFailedToRestore(): void
    {
        $disableRawModeCalls = 0;
        $terminal = $this->createMock(Terminal::class);
        $terminal->expects(self::exactly(2))->method('disableRawMode')->willReturnCallback(
            static function () use (&$disableRawModeCalls): void {
                if (++$disableRawModeCalls === 1) {
                    throw new RuntimeException('stty failed');
                }
            },
        );

        $baseline = ShutdownRestorer::activeCount();
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $session->close();

        self::assertSame(TerminalSessionState::Closed, $session->state());
        self::assertSame($baseline + 1, ShutdownRestorer::activeCount());

        $session->close();

        self::assertSame($baseline, ShutdownRestorer::activeCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function restorations(): iterable
    {
        yield 'raw mode' => ['disableRawMode'];
        yield 'bracketed paste' => ['disableBracketedPaste'];
        yield 'cursor' => ['showCursor'];
        yield 'alt screen' => ['leaveAltScreen'];
        yield 'mouse tracking' => ['disableMouseTracking'];
        yield 'kitty protocol' => ['disableKittyProtocol'];
    }

    #[Test]
    #[DataProvider('restorations')]
    public function aModeThatNeverRestoresKeepsTheSessionRegistered(string $restoration): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->method($restoration)->willThrowException(new RuntimeException('restore failed'));

        $baseline = ShutdownRestorer::activeCount();
        $session = new TerminalSession($terminal, true, true);
        $session->start();
        $session->enableKittyProtocol();

        try {
            $session->close();

            self::assertSame(TerminalSessionState::Closed, $session->state());
            self::assertSame($baseline + 1, ShutdownRestorer::activeCount());
        } finally {
            ShutdownRestorer::unregister($session);
        }
    }

    #[Test]
    public function suspendDoesNotMoveTheCursorToTheBottomOfTheScreen(): void
    {
        $terminal = new VirtualTerminal(80, 7);
        $surface = new FullScreenSurface($terminal, new Screen($terminal), false);
        $session = new TerminalSession($terminal, false, false, $surface);
        $session->start();
        $terminal->clearOutput();

        $session->suspend();

        self::assertStringContainsString(Ansi::RESET_STYLE, $terminal->output());
        self::assertStringNotContainsString(Ansi::cursorToRowStart(6), $terminal->output());

        $session->close();
    }

    #[Test]
    public function suspendingAnInlineSessionParksTheCursorBelowTheRegionForTheShell(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(new Frame(80, 3));
        $session = new TerminalSession($terminal, false, false, new InlineSurface($screen));
        $session->start();
        $terminal->clearOutput();

        $session->suspend();

        self::assertStringContainsString("\x1b[2B\r\n", $terminal->output());

        $session->close();
    }

    #[Test]
    public function theTerminalGetsItsOwnCaretShapeBackOnSuspendAndClose(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $terminal->clearOutput();

        $session->suspend();
        $suspended = $terminal->output();
        $session->resume();
        $terminal->clearOutput();
        $session->close();

        self::assertStringContainsString("\x1b[0 q", $suspended);
        self::assertStringContainsString("\x1b[0 q", $terminal->output());
    }

    #[Test]
    public function closingEndsASynchronizedUpdateAFailedFrameLeftOpen(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $terminal->write(Ansi::SYNC_START . 'half a fra');
        $terminal->clearOutput();

        $session->close();

        self::assertStringStartsWith(Ansi::SYNC_END, $terminal->output());
    }

    #[Test]
    public function startRespectsDisabledAltScreenAndMouseTracking(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        self::assertFalse($terminal->isAltScreenEnabled());
        self::assertFalse($terminal->isMouseTrackingEnabled());

        $session->close();
    }

    #[Test]
    public function aStartedSessionAsksWhetherTheTerminalSpeaksTheKittyProtocol(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $session = new TerminalSession($terminal, false, false);

        $session->start();

        self::assertStringContainsString(Ansi::QUERY_KITTY_KEYBOARD, $terminal->output());
        $session->close();
    }

    #[Test]
    public function closeReleasesTheKittyProtocolRegistration(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $baseline = ShutdownRestorer::activeCount();
        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $session->enableKittyProtocol();

        $session->close();

        self::assertFalse($terminal->isKittyEnabled());
        self::assertSame($baseline, ShutdownRestorer::activeCount());
    }

    #[Test]
    public function closeFlushesPendingTerminalOutput(): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->expects(self::once())->method('flush');

        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $session->close();
    }
}
