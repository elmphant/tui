<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

/**
 * @internal
 */
trait ChildProcessLifecycle
{
    /**
     * Symfony's stop() blocks until its timeout before using SIGKILL; the 10-second default would freeze the event loop.
     */
    private const TERMINATION_GRACE_SECONDS = 0.05;

    private bool $finished = false;

    public function map(Message $message): Message
    {
        return $this->wrap === null ? $message : ($this->wrap)($message);
    }

    public function stop(): void
    {
        if ($this->finished) {
            return;
        }

        $pid = $this->process->getPid();
        $descendants = $pid === null ? [] : ProcessTree::descendantsOf($pid);
        ProcessTree::terminate($descendants);
        $this->process->stop(self::TERMINATION_GRACE_SECONDS);
        ProcessTree::kill($descendants);
        $this->finished = true;
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
