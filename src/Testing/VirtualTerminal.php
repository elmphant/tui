<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Input\ColorScheme;
use Elmphant\Tui\Input\Terminal;
use Elmphant\Tui\Rendering\Ansi;
use Elmphant\Tui\Rendering\TerminalText;
use RuntimeException;

final class VirtualTerminal implements Terminal
{
    private string $output = '';
    private bool $rawModeEnabled = false;
    private bool $altScreenEnabled = false;
    private bool $cursorVisible = true;
    private bool $mouseTrackingEnabled = false;
    private int $sizeCalls = 0;
    private int $probeSizeCalls = 0;

    /**
     * @var resource
     */
    private mixed $readPipe;
    /**
     * @var resource
     */
    private mixed $writePipe;

    private bool $bracketedPasteEnabled = false;

    private bool $kittyEnabled = false;

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly ColorProfile $simulatedColorProfile = ColorProfile::TrueColor,
    ) {
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pipes === false) {
            throw new RuntimeException('Unable to create virtual terminal socket pair');
        }
        [$this->readPipe, $this->writePipe] = $pipes;
        stream_set_blocking($this->readPipe, false);
        stream_set_blocking($this->writePipe, false);
    }

    public function enableRawMode(): void
    {
        $this->rawModeEnabled = true;
    }

    public function disableRawMode(): void
    {
        $this->rawModeEnabled = false;
    }

    public function isRawModeEnabled(): bool
    {
        return $this->rawModeEnabled;
    }

    public function enterAltScreen(): void
    {
        $this->altScreenEnabled = true;
    }

    public function leaveAltScreen(): void
    {
        $this->altScreenEnabled = false;
    }

    public function isAltScreenEnabled(): bool
    {
        return $this->altScreenEnabled;
    }

    public function hideCursor(): void
    {
        $this->cursorVisible = false;
    }

    public function showCursor(): void
    {
        $this->cursorVisible = true;
        $this->write("\x1b[?25h");
    }

    public function isCursorVisible(): bool
    {
        return $this->cursorVisible;
    }

    public function enableMouseTracking(): void
    {
        $this->mouseTrackingEnabled = true;
    }

    public function disableMouseTracking(): void
    {
        $this->mouseTrackingEnabled = false;
    }

    public function isMouseTrackingEnabled(): bool
    {
        return $this->mouseTrackingEnabled;
    }

    public function enableBracketedPaste(): void
    {
        $this->bracketedPasteEnabled = true;
    }

    public function disableBracketedPaste(): void
    {
        $this->bracketedPasteEnabled = false;
    }

    public function isBracketedPasteEnabled(): bool
    {
        return $this->bracketedPasteEnabled;
    }

    public function queryKittyProtocol(): void
    {
        $this->write(Ansi::QUERY_KITTY_KEYBOARD);
    }

    public function enableKittyProtocol(): void
    {
        $this->kittyEnabled = true;
        $this->write(Ansi::ENABLE_KITTY_KEYBOARD);
    }

    public function disableKittyProtocol(): void
    {
        $this->kittyEnabled = false;
        $this->write(Ansi::DISABLE_KITTY_KEYBOARD);
    }

    public function isKittyEnabled(): bool
    {
        return $this->kittyEnabled;
    }

    public function bell(): void
    {
        $this->write("\x07");
    }

    public function setTitle(string $title): void
    {
        $sanitized = TerminalText::singleLine($title);
        $this->write("\x1b]0;{$sanitized}\x07");
    }

    /**
     * @return array{int, int}
     */
    public function size(): array
    {
        ++$this->sizeCalls;

        return [$this->width, $this->height];
    }

    /**
     * @return array{int, int}|null
     */
    public function probeSize(): ?array
    {
        ++$this->probeSizeCalls;

        return [$this->width, $this->height];
    }

    public function sizeCallCount(): int
    {
        return $this->sizeCalls;
    }

    public function probeSizeCallCount(): int
    {
        return $this->probeSizeCalls;
    }

    public function colorProfile(): ColorProfile
    {
        return $this->simulatedColorProfile;
    }

    public function detectColorScheme(): ?ColorScheme
    {
        return ColorScheme::Dark;
    }

    public function readInput(): string
    {
        return (string)@fread($this->readPipe, 4096);
    }

    public function waitForInput(int $timeoutMicroseconds): bool
    {
        $read = [$this->readPipe];
        $write = $except = null;

        return @stream_select($read, $write, $except, 0, 0) > 0;
    }

    public function write(string $data): void
    {
        $this->output .= $data;
    }

    public function flush(): void
    {
    }

    public function inputStream(): mixed
    {
        return $this->readPipe;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function clearOutput(): void
    {
        $this->output = '';
    }

    public function simulateInput(string $bytes): void
    {
        fwrite($this->writePipe, $bytes);
    }
}
