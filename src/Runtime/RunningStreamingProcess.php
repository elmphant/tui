<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessStream;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class RunningStreamingProcess implements RunningProcess
{
    /**
     * Symfony's stop() blocks in a usleep loop until the child dies or this timeout
     * expires, and only then escalates to SIGKILL; its 10s default would freeze the
     * event loop for a child that ignores SIGTERM.
     */
    private const TERMINATION_GRACE_SECONDS = 0.05;

    private bool $finished = false;

    public function __construct(
        private readonly Process $process,
        private readonly ?EffectId $processId,
        private readonly int $outputChunkBytes = 65_536,
        /**
         * @var Closure(Message): Message|null
         */
        private readonly ?Closure $wrap = null,
    ) {
    }

    public function pump(): array
    {
        if ($this->finished) {
            return [];
        }

        $messages = $this->drainOutput();

        if ($this->process->isRunning()) {
            return $messages;
        }

        $this->finished = true;

        // isRunning() reads the pipes into Symfony's own buffer, so a flush the child
        // made between the drain above and this check is only visible now.
        $messages = [...$messages, ...$this->drainOutput()];

        $messages[] = $this->map(new ProcessFinishedMsg(
            $this->process->getExitCode() ?? -1,
            $this->process->isSuccessful(),
            $this->processId,
        ));

        return $messages;
    }

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
        return $this->processId;
    }

    /**
     * @return list<Message>
     */
    private function drainOutput(): array
    {
        $messages = [];

        $stdout = $this->process->getIncrementalOutput();
        if ($stdout !== '') {
            foreach (str_split($stdout, max(1, $this->outputChunkBytes)) as $chunk) {
                $messages[] = $this->map(new ProcessOutputMsg($chunk, ProcessStream::Stdout, $this->processId));
            }
        }
        $this->process->clearOutput();

        $stderr = $this->process->getIncrementalErrorOutput();
        if ($stderr !== '') {
            foreach (str_split($stderr, max(1, $this->outputChunkBytes)) as $chunk) {
                $messages[] = $this->map(new ProcessOutputMsg($chunk, ProcessStream::Stderr, $this->processId));
            }
        }
        $this->process->clearErrorOutput();

        return $messages;
    }
}
