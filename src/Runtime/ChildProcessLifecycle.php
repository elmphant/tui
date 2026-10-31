<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * @internal
 */
trait ChildProcessLifecycle
{
    /**
     * Symfony's stop() blocks until its timeout before using SIGKILL; the 10-second default would freeze the event loop.
     */
    private const TERMINATION_GRACE_SECONDS = 0.05;
    private const SIGTERM = 15;

    private bool $finished = false;
    private ?float $askedToStopAt = null;

    /**
     * @var list<int>
     */
    private array $descendants = [];

    public function map(Message $message): Message
    {
        return $this->wrap === null ? $message : ($this->wrap)($message);
    }

    public function askToStop(): void
    {
        if ($this->finished || $this->askedToStopAt !== null) {
            return;
        }

        $this->askedToStopAt = microtime(true);
        $pid = $this->process->getPid();
        $this->descendants = $pid === null ? [] : ProcessTree::descendantsOf($pid);
        // Parent first: a shell whose child dies first runs its next command before its own signal arrives.
        try {
            $this->process->signal(self::SIGTERM);
        } catch (LogicException|RuntimeException) {
            // It ended on its own in the meantime.
        }
    }

    public function stop(): void
    {
        if ($this->finished) {
            return;
        }

        $this->askToStop();
        $this->process->stop(max(0.0, (float)$this->askedToStopAt + self::TERMINATION_GRACE_SECONDS - microtime(true)));
        ProcessTree::kill($this->descendants);
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

    public function secondsLeft(): ?float
    {
        $timeout = $this->process->getTimeout();
        if ($timeout === null || !$this->process->isStarted()) {
            return null;
        }

        return $timeout - (microtime(true) - $this->process->getStartTime());
    }

    /**
     * Symfony's checkTimeout() kills only the parent before it throws, and its children are then out of reach.
     */
    private function stopIfTimedOut(): void
    {
        if (($this->secondsLeft() ?? 0.0) >= 0.0) {
            return;
        }

        $this->stop();

        throw new ProcessTimedOutException($this->process, ProcessTimedOutException::TYPE_GENERAL);
    }
}
