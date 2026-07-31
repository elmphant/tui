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

        if (!$this->process->isRunning()) {
            $this->finished = true;
            $messages[] = $this->map(new ProcessFinishedMsg(
                $this->process->getExitCode() ?? -1,
                $this->process->isSuccessful(),
                $this->processId,
            ));
        }

        return $messages;
    }

    public function map(Message $message): Message
    {
        return $this->wrap === null ? $message : ($this->wrap)($message);
    }

    public function stop(): void
    {
        if (!$this->finished) {
            $this->process->stop();
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
}
