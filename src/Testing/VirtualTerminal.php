<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\ColorScheme;
use Elmphant\Tui\Terminal\Terminal;
use Elmphant\Tui\Text\TerminalText;
use RuntimeException;

final class VirtualTerminal implements Terminal
{
    private string $output = '';
    private bool $rawModeEnabled = false;
    private bool $altScreenEnabled = false;
    private bool $cursorVisible = true;
    private bool $mouseTrackingEnabled = false;
    private int $sizeCalls = 0;

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
        private int $width,
        private int $height,
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
     * What a window manager does to the real one; the program hears of it by SIGWINCH.
     */
    public function resizeTo(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * @return array{int, int}
     */
    public function size(): array
    {
        ++$this->sizeCalls;

        return [$this->width, $this->height];
    }

    public function sizeCallCount(): int
    {
        return $this->sizeCalls;
    }

    public function colorProfile(): ColorProfile
    {
        return $this->simulatedColorProfile;
    }

    public function detectColorScheme(): ?ColorScheme
    {
        return ColorScheme::Dark;
    }

    public function readInput(): ?string
    {
        $input = (string)@fread($this->readPipe, 4096);

        return $input === '' && feof($this->readPipe) ? null : $input;
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

    public function output(): string
    {
        return $this->output;
    }

    public function clearOutput(): void
    {
        $this->output = '';
    }

    public function closeInput(): void
    {
        fclose($this->writePipe);
    }

    public function simulateInput(string $bytes): void
    {
        fwrite($this->writePipe, $bytes);
    }
}
