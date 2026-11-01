<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\Terminal;
use LogicException;
use Throwable;

/**
 * @internal
 */
final class TerminalSession
{
    private TerminalSessionState $state = TerminalSessionState::New;
    private bool $rawModeActive = false;
    private bool $bracketedPasteActive = false;
    private bool $cursorHidden = false;
    private bool $altScreenActive = false;
    private bool $mouseTrackingActive = false;
    private bool $kittySupported = false;
    private bool $kittyProtocolActive = false;

    public function __construct(
        private readonly Terminal $terminal,
        private readonly bool $useAltScreen,
        private readonly bool $useMouseTracking,
        private readonly ?Surface $surface = null,
    ) {
    }

    public function state(): TerminalSessionState
    {
        return $this->state;
    }

    public function start(): void
    {
        if ($this->state !== TerminalSessionState::New) {
            throw new LogicException('Terminal session can only be started once');
        }

        ShutdownRestorer::register($this);

        try {
            $this->activateBaseModes();
            $this->state = TerminalSessionState::Active;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function enableKittyProtocol(): void
    {
        $this->kittySupported = true;

        if ($this->state === TerminalSessionState::Active && !$this->kittyProtocolActive) {
            $this->terminal->enableKittyProtocol();
            $this->kittyProtocolActive = true;
        }
    }

    public function suspend(): void
    {
        if ($this->state !== TerminalSessionState::Active) {
            return;
        }

        $this->state = TerminalSessionState::Suspended;
        $this->restoreActiveModes(final: false);
    }

    public function resume(): void
    {
        if ($this->state !== TerminalSessionState::Suspended) {
            return;
        }

        try {
            $this->activateBaseModes();
            if ($this->kittySupported) {
                $this->terminal->enableKittyProtocol();
                $this->kittyProtocolActive = true;
            }
            $this->state = TerminalSessionState::Active;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function close(): void
    {
        if ($this->state === TerminalSessionState::Closed && !$this->hasActiveModes()) {
            return;
        }

        $this->state = TerminalSessionState::Closed;
        $this->restoreActiveModes(final: true);
        if (!$this->hasActiveModes()) {
            ShutdownRestorer::unregister($this);
        }
    }

    private function activateBaseModes(): void
    {
        if (!$this->rawModeActive) {
            $this->terminal->enableRawMode();
            $this->rawModeActive = true;
        }
        if (!$this->bracketedPasteActive) {
            $this->terminal->enableBracketedPaste();
            $this->bracketedPasteActive = true;
        }
        if (!$this->cursorHidden) {
            $this->terminal->hideCursor();
            $this->cursorHidden = true;
        }
        if ($this->useAltScreen && !$this->altScreenActive) {
            $this->terminal->enterAltScreen();
            $this->altScreenActive = true;
        }
        if ($this->useMouseTracking && !$this->mouseTrackingActive) {
            $this->terminal->enableMouseTracking();
            $this->mouseTrackingActive = true;
        }
    }

    private function restoreActiveModes(bool $final): void
    {
        $hadActiveModes = $this->hasActiveModes();

        if ($hadActiveModes) {
            $this->attempt(fn () => $this->terminal->write(Ansi::SYNC_END));
        }
        if ($this->mouseTrackingActive) {
            $this->mouseTrackingActive = !$this->attempt($this->terminal->disableMouseTracking(...));
        }
        if ($this->kittyProtocolActive) {
            $this->kittyProtocolActive = !$this->attempt($this->terminal->disableKittyProtocol(...));
        }
        if ($this->altScreenActive) {
            $this->altScreenActive = !$this->attempt($this->terminal->leaveAltScreen(...));
        }
        if ($this->bracketedPasteActive) {
            $this->bracketedPasteActive = !$this->attempt($this->terminal->disableBracketedPaste(...));
        }
        if ($this->cursorHidden) {
            $this->cursorHidden = !$this->attempt($this->terminal->showCursor(...));
        }

        if ($hadActiveModes) {
            $this->attempt(fn () => $this->terminal->write(Ansi::RESET_STYLE . Ansi::DEFAULT_CURSOR_SHAPE));
            if ($this->surface !== null) {
                $surface = $this->surface;
                $this->attempt(fn () => $this->terminal->write($final ? $surface->leave() : $surface->pause()));
            }
            $this->attempt($this->terminal->flush(...));
        }

        if ($this->rawModeActive) {
            $this->rawModeActive = !$this->attempt($this->terminal->disableRawMode(...));
        }
    }

    private function hasActiveModes(): bool
    {
        return $this->rawModeActive
            || $this->bracketedPasteActive
            || $this->cursorHidden
            || $this->altScreenActive
            || $this->mouseTrackingActive
            || $this->kittyProtocolActive;
    }

    /**
     * @param Closure(): void $operation
     */
    private function attempt(Closure $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable) {
            // Restoration is best-effort: one failed mode must not prevent the rest.
            return false;
        }
    }
}
