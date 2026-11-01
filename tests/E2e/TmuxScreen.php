<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\E2e;

use RuntimeException;

/**
 * Run an isolated tmux server and read the pane after its output settles.
 */
final class TmuxScreen
{
    private const SETTLE_SECONDS = 0.1;
    private const TIMEOUT_SECONDS = 5.0;

    private readonly string $socket;

    private function __construct()
    {
        $this->socket = sprintf('e2e-%d-%s', getmypid(), bin2hex(random_bytes(4)));
    }

    public static function available(): bool
    {
        exec('command -v tmux 2>/dev/null', $output, $status);

        return $status === 0;
    }

    /**
     * Keep the pane open after exit so tests can inspect its final screen.
     */
    public static function running(string $command, int $width = 80, int $height = 24): self
    {
        $screen = new self();
        $screen->tmux(sprintf(
            'new-session -d -s e2e -x %d -y %d -c %s %s',
            $width,
            $height,
            escapeshellarg(dirname(__DIR__, 2)),
            escapeshellarg($command . '; echo E2E-EXITED $?; sleep 30'),
        ));

        return $screen;
    }

    public function keys(string ...$keys): void
    {
        $this->tmux('send-keys -t e2e ' . implode(' ', array_map(escapeshellarg(...), $keys)));
    }

    public function text(string $text): void
    {
        $this->tmux('send-keys -t e2e -l ' . escapeshellarg($text));
    }

    public function resize(int $width, int $height): void
    {
        $this->tmux(sprintf('resize-window -t e2e -x %d -y %d', $width, $height));
    }

    public function screen(): string
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $previous = $this->capture();

        while (microtime(true) < $deadline) {
            usleep((int)(self::SETTLE_SECONDS * 1_000_000));
            $current = $this->capture();
            if ($current === $previous) {
                return $current;
            }
            $previous = $current;
        }

        throw new RuntimeException("The screen never settled:\n" . $previous);
    }

    public function waitFor(string $text): string
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $screen = $this->capture();
            if (str_contains($screen, $text)) {
                return $this->screen();
            }
            usleep(50_000);
        }

        throw new RuntimeException(sprintf("\"%s\" never appeared:\n%s", $text, $this->capture()));
    }

    public function withHistory(): string
    {
        $this->screen();

        return rtrim(implode("\n", array_map(rtrim(...), explode("\n", $this->tmux('capture-pane -p -S - -t e2e')))));
    }

    public function flag(string $name): string
    {
        return trim($this->tmux(sprintf('display -p -t e2e %s', escapeshellarg("#{{$name}}"))));
    }

    public function close(): void
    {
        exec(sprintf('tmux -L %s kill-server 2>/dev/null', escapeshellarg($this->socket)));
    }

    private function capture(): string
    {
        return rtrim(implode("\n", array_map(rtrim(...), explode("\n", $this->tmux('capture-pane -p -t e2e')))));
    }

    private function tmux(string $arguments): string
    {
        exec(sprintf('tmux -L %s -f /dev/null %s 2>&1', escapeshellarg($this->socket), $arguments), $output, $status);
        if ($status !== 0) {
            throw new RuntimeException(sprintf('tmux %s failed: %s', $arguments, implode("\n", $output)));
        }

        return implode("\n", $output);
    }
}
