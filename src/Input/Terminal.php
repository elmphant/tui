<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Rendering\Ansi;
use Elmphant\Tui\Rendering\TerminalText;
use Throwable;
use ValueError;

final class Terminal implements TerminalInterface, ReportsOutputStats
{
    private const WRITE_TIMEOUT_MICROSECONDS = 100_000;

    private string $savedTerminalSettings = '';
    private bool $rawModeEnabled = false;
    private ?StreamBlockingState $stdinBlockingState = null;
    private ?ColorProfile $detectedColorProfile = null;
    private string $stdinLeftover = '';
    private int $bytesWritten = 0;
    private float $writeWaitSeconds = 0.0;

    /**
     * @var resource
     */
    private readonly mixed $stdin;
    /**
     * @var resource
     */
    private readonly mixed $stdout;

    /**
     * @param resource|null $stdin
     * @param resource|null $stdout
     */
    public function __construct($stdin = null, $stdout = null)
    {
        $this->stdin = $stdin ?? \STDIN;
        $this->stdout = $stdout ?? \STDOUT;
    }

    public function enableRawMode(): void
    {
        if ($this->rawModeEnabled) {
            return;
        }

        $this->assertPosixTty();
        $blockingState = StreamBlockingState::capture($this->stdin);
        $savedSettings = trim($this->runStty(['-g']));
        if ($savedSettings === '') {
            throw new TerminalException('Unable to capture terminal settings before enabling raw mode');
        }

        $this->runStty(['raw', '-echo', '-icanon', '-ixon', '-ixoff', 'min', '1', 'time', '0']);
        if (!$blockingState->makeNonBlocking()) {
            try {
                $this->runStty([$savedSettings]);
            } catch (Throwable) {
            }
            throw new TerminalException('Unable to make terminal input non-blocking');
        }

        $this->savedTerminalSettings = $savedSettings;
        $this->stdinBlockingState = $blockingState;
        $this->rawModeEnabled = true;
    }

    public function disableRawMode(): void
    {
        if (!$this->rawModeEnabled) {
            return;
        }

        $settings = $this->savedTerminalSettings !== '' ? [$this->savedTerminalSettings] : ['sane'];
        $sttyFailure = null;
        try {
            $this->runStty($settings);
        } catch (Throwable $error) {
            $sttyFailure = $error;
        }

        $blockingRestored = $this->stdinBlockingState === null || $this->stdinBlockingState->restore();

        if ($sttyFailure !== null) {
            throw $sttyFailure;
        }
        if (!$blockingRestored) {
            throw new TerminalException('Terminal settings were restored, but the inherited input blocking mode could not be restored');
        }

        $this->rawModeEnabled = false;
        $this->savedTerminalSettings = '';
        $this->stdinBlockingState = null;
    }

    public function enterAltScreen(): void
    {
        $this->write(Ansi::ENTER_ALT_SCREEN);
    }

    public function leaveAltScreen(): void
    {
        $this->write(Ansi::LEAVE_ALT_SCREEN);
    }

    public function hideCursor(): void
    {
        $this->write(Ansi::HIDE_CURSOR);
    }

    public function showCursor(): void
    {
        $this->write(Ansi::SHOW_CURSOR);
    }

    public function enableBracketedPaste(): void
    {
        $this->write(Ansi::ENABLE_BRACKETED_PASTE);
    }

    public function disableBracketedPaste(): void
    {
        $this->write(Ansi::DISABLE_BRACKETED_PASTE);
    }

    public function enableMouseTracking(): void
    {
        $this->write(Ansi::ENABLE_MOUSE_BASIC . Ansi::ENABLE_MOUSE_MOTION . Ansi::ENABLE_MOUSE_SGR);
    }

    public function disableMouseTracking(): void
    {
        $this->write(Ansi::DISABLE_MOUSE_SGR . Ansi::DISABLE_MOUSE_MOTION . Ansi::DISABLE_MOUSE_BASIC);
    }

    public function queryKittyProtocol(): void
    {
        $this->write(Ansi::QUERY_KITTY_KEYBOARD);
    }

    public function enableKittyProtocol(): void
    {
        $this->write(Ansi::ENABLE_KITTY_KEYBOARD);
    }

    public function disableKittyProtocol(): void
    {
        $this->write(Ansi::DISABLE_KITTY_KEYBOARD);
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
     * @return array{int, int} [width, height]
     */
    public function size(): array
    {
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('proc_open')
            && function_exists('stream_isatty') && stream_isatty($this->stdin)) {
            try {
                $output = $this->runStty(['size']);
                if (preg_match('/(\d+)\s+(\d+)/', $output, $matches) === 1) {
                    return [(int)$matches[2], (int)$matches[1]];
                }
            } catch (TerminalException) {
                // Environment dimensions below are the non-interactive fallback.
            }
        }
        $columns = getenv('COLUMNS');
        $lines = getenv('LINES');

        return [
            (int)($columns !== false && $columns !== '' ? $columns : 80),
            (int)($lines !== false && $lines !== '' ? $lines : 24),
        ];
    }

    /**
     * Probe terminal size via cursor position report.
     * Only safe to call before user interaction starts (stdin must be clean).
     *
     * @return array{int, int}|null [width, height]
     */
    public function probeSize(): ?array
    {
        @fwrite($this->stdout, Ansi::SAVE_CURSOR . Ansi::CURSOR_TO_BOTTOM_RIGHT . Ansi::REPORT_CURSOR_POSITION);
        @fflush($this->stdout);

        $read = [$this->stdin];
        $write = $except = null;
        if (@stream_select($read, $write, $except, 0, 100_000) <= 0) {
            @fwrite($this->stdout, Ansi::RESTORE_CURSOR);
            @fflush($this->stdout);

            return null;
        }

        $response = (string)@fread($this->stdin, 32);
        @fwrite($this->stdout, Ansi::RESTORE_CURSOR);
        @fflush($this->stdout);

        if (preg_match('/\x1b\[(\d+);(\d+)R/', $response, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $matchStart = $matches[0][1];
            $matchLength = strlen($matches[0][0]);
            $before = substr($response, 0, $matchStart);
            $after = substr($response, $matchStart + $matchLength);
            $this->stdinLeftover .= $before . $after;

            return [(int)$matches[2][0], (int)$matches[1][0]];
        }

        $this->stdinLeftover .= $response;

        return null;
    }

    public function colorProfile(): ColorProfile
    {
        if ($this->detectedColorProfile !== null) {
            return $this->detectedColorProfile;
        }

        if (getenv('NO_COLOR') !== false) {
            return $this->detectedColorProfile = ColorProfile::Ascii;
        }

        $colorTerm = getenv('COLORTERM');
        if ($colorTerm === 'truecolor' || $colorTerm === '24bit') {
            return $this->detectedColorProfile = ColorProfile::TrueColor;
        }

        $termValue = getenv('TERM');
        $term = $termValue !== false ? $termValue : '';
        if (str_contains($term, '256color') || str_contains($term, '256colour')) {
            return $this->detectedColorProfile = ColorProfile::Ansi256;
        }

        if ($term !== '' && $term !== 'dumb') {
            return $this->detectedColorProfile = ColorProfile::Ansi;
        }

        return $this->detectedColorProfile = ColorProfile::Ascii;
    }

    public function detectColorScheme(): ?ColorScheme
    {
        $this->write("\033]11;?\033\\");
        if (!$this->waitForInput(100_000)) {
            return null;
        }

        $response = (string)@fread($this->stdin, 256);
        if (preg_match('/\033\]11;rgb:([0-9a-f]{2,4})\/([0-9a-f]{2,4})\/([0-9a-f]{2,4})\x1b\\\\?/', $response, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->stdinLeftover .= $response;

            return null;
        }

        $matchStart = $matches[0][1];
        $matchLength = strlen($matches[0][0]);
        $before = substr($response, 0, $matchStart);
        $after = substr($response, $matchStart + $matchLength);
        $this->stdinLeftover .= $before . $after;

        return self::classifyColorScheme($matches[1][0], $matches[2][0], $matches[3][0]);
    }

    public function readInput(): string
    {
        $leftover = $this->stdinLeftover;
        $this->stdinLeftover = '';

        return $leftover . (string)@fread($this->stdin, 4096);
    }

    public function waitForInput(int $timeoutMicroseconds): bool
    {
        $read = [$this->stdin];
        $write = $except = null;
        $seconds = (int)($timeoutMicroseconds / 1_000_000);
        $microseconds = $timeoutMicroseconds % 1_000_000;

        return @stream_select($read, $write, $except, $seconds, $microseconds) > 0;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function writeWaitSeconds(): float
    {
        return $this->writeWaitSeconds;
    }

    public function write(string $data): void
    {
        $total = strlen($data);
        $remaining = $total;
        $offset = 0;
        $this->bytesWritten += $total;

        while ($remaining > 0) {
            $written = @fwrite($this->stdout, substr($data, $offset));

            if ($written === false) {
                throw new TerminalWriteException($offset, $total);
            }

            if ($written === 0) {
                $read = null;
                $write = [$this->stdout];
                $except = null;
                $waitStart = hrtime(true);
                try {
                    $ready = @stream_select($read, $write, $except, 0, self::WRITE_TIMEOUT_MICROSECONDS);
                } catch (ValueError) {
                    $ready = false;
                }
                $this->writeWaitSeconds += (hrtime(true) - $waitStart) / 1e9;
                if ($ready === false || $ready <= 0) {
                    throw new TerminalWriteException($offset, $total);
                }
                continue;
            }

            $offset += $written;
            $remaining -= $written;
        }
    }

    public function flush(): void
    {
        if (!@fflush($this->stdout)) {
            throw new TerminalException('Unable to flush terminal output');
        }
    }

    public function inputStream(): mixed
    {
        return $this->stdin;
    }

    private function assertPosixTty(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new TerminalException('Interactive terminal mode is currently supported on POSIX systems only');
        }
        if (!stream_isatty($this->stdin)) {
            throw new TerminalException('Interactive terminal mode requires stdin to be a TTY');
        }
        if (!stream_isatty($this->stdout)) {
            throw new TerminalException('Interactive terminal mode requires stdout to be a TTY');
        }
        if (!function_exists('proc_open')) {
            throw new TerminalException('Interactive terminal mode requires proc_open() to execute stty');
        }
    }

    private static function classifyColorScheme(string $redHex, string $greenHex, string $blueHex): ColorScheme
    {
        $red = hexdec(substr($redHex, 0, 2)) / 255;
        $green = hexdec(substr($greenHex, 0, 2)) / 255;
        $blue = hexdec(substr($blueHex, 0, 2)) / 255;
        $luminance = 0.299 * $red + 0.587 * $green + 0.114 * $blue;

        return $luminance < 0.5 ? ColorScheme::Dark : ColorScheme::Light;
    }

    /**
     * @param list<string> $arguments
     */
    private function runStty(array $arguments): string
    {
        $descriptorSpec = [
            0 => $this->stdin,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(['stty', ...$arguments], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new TerminalException('Unable to start stty');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new TerminalException(sprintf('stty failed%s', trim((string)$stderr) === '' ? '' : ': ' . trim((string)$stderr)));
        }

        return (string)$stdout;
    }
}
