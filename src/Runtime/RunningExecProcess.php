<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ProcessResultMsg;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * @internal
 */
final class RunningExecProcess implements RunningProcess
{
    /**
     * Symfony's stop() blocks in a usleep loop until the child dies or this timeout
     * expires, and only then escalates to SIGKILL; its 10s default would freeze the
     * event loop for a child that ignores SIGTERM.
     */
    private const TERMINATION_GRACE_SECONDS = 0.05;

    private bool $finished = false;
    private string $stdout = '';
    private string $stderr = '';

    public function __construct(
        private readonly Process $process,
        private readonly ?EffectId $processId,
        private readonly int $maxOutputBytes = 8_388_608,
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

        try {
            $this->collectOutput();
            $this->process->clearOutput();
            $this->process->clearErrorOutput();

            if (strlen($this->stdout) + strlen($this->stderr) > $this->maxOutputBytes) {
                $this->stop();

                return [$this->map(new ErrorMsg(
                    new EffectOutputLimitExceeded($this->maxOutputBytes, 'Process'),
                    'process',
                    $this->processId,
                ))];
            }

            if ($this->process->isRunning()) {
                return [];
            }

            // isRunning() reads the pipes into Symfony's own buffer, so a flush the
            // child made between the read above and this check is only visible now.
            $this->collectOutput();
        } catch (Throwable $error) {
            $this->finished = true;

            return [$this->map(new ErrorMsg($error, 'process', $this->processId))];
        }

        $this->finished = true;

        return [$this->map(new ProcessResultMsg(
            $this->stdout,
            $this->stderr,
            $this->process->getExitCode() ?? -1,
            $this->process->isSuccessful(),
            $this->processId,
        ))];
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

    private function collectOutput(): void
    {
        $this->stdout .= $this->process->getIncrementalOutput();
        $this->stderr .= $this->process->getIncrementalErrorOutput();
    }
}
