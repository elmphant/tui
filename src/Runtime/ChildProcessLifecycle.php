<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

/**
 * Starting, stopping and identifying a child process, for the effects that run
 * one. What they do with its output is their own; this is the part that is the
 * same whether the output arrives all at once or in chunks.
 *
 * @internal
 */
trait ChildProcessLifecycle
{
    /**
     * Symfony's stop() blocks in a usleep loop until the child dies or this timeout
     * expires, and only then escalates to SIGKILL; its 10s default would freeze the
     * event loop for a child that ignores SIGTERM.
     */
    private const TERMINATION_GRACE_SECONDS = 0.05;

    private bool $finished = false;

    public function map(Message $message): Message
    {
        return $this->wrap === null ? $message : ($this->wrap)($message);
    }

    public function stop(): void
    {
        if (!$this->finished) {
            $this->process->stop(self::TERMINATION_GRACE_SECONDS);
            $this->finished = true;
        }
    }

    public function isRunning(): bool
    {
        return !$this->finished;
    }

    public function id(): ?EffectId
    {
        return $this->effectId;
    }
}
