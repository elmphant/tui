<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Cmd\ForkCmd;

/**
 * @internal a forked child as its parent sees it: the socket it writes to, and its process to reap or kill
 */
final class RunningFork
{
    /**
     * Read at most this much per pass, so a child that floods its parent cannot hold up the keyboard.
     */
    private const READ_PER_PASS = 65536;

    public string $watcherId = '';

    private string $buffer = '';

    private bool $endOfFile = false;

    private bool $over = false;

    /**
     * @param resource $socket
     */
    private function __construct(
        public readonly int $pid,
        public readonly mixed $socket,
    ) {
    }

    /**
     * Null when no socket pair or process could be made; the work then runs in the program itself.
     */
    public static function start(ForkCmd $command): ?self
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($pair === false) {
            return null;
        }

        [$parentEnd, $childEnd] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentEnd);
            fclose($childEnd);

            return null;
        }

        if ($pid === 0) {
            fclose($parentEnd);
            ForkChild::run($command, $childEnd);
        }

        fclose($childEnd);
        stream_set_blocking($parentEnd, false);

        return new self($pid, $parentEnd);
    }

    /**
     * @return list<array{ForkFrame, string}>
     */
    public function read(): array
    {
        $chunk = fread($this->socket, self::READ_PER_PASS);

        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            $this->endOfFile = true;
        } else {
            $this->buffer .= $chunk;
        }

        return ForkFrame::takeFrom($this->buffer);
    }

    public function endedWithoutItsLastFrame(): bool
    {
        return $this->endOfFile && !$this->over;
    }

    /**
     * The child ends itself right after its last frame, so waiting for it takes no time.
     */
    public function close(): void
    {
        if ($this->over) {
            return;
        }

        $this->forget();
        pcntl_waitpid($this->pid, $status);
    }

    /**
     * Its own children go too: a child that started programs would leave them running.
     */
    public function kill(): void
    {
        if ($this->over) {
            return;
        }

        $descendants = ProcessTree::descendantsOf($this->pid);
        $this->forget();
        ProcessTree::kill([$this->pid, ...$descendants]);
        pcntl_waitpid($this->pid, $status);
    }

    private function forget(): void
    {
        $this->over = true;
        \Revolt\EventLoop::cancel($this->watcherId);
        fclose($this->socket);
    }
}
