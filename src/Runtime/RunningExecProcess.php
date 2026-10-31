<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\ProcessResultMsg;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * @internal
 */
final class RunningExecProcess implements RunningProcess
{
    use ChildProcessLifecycle;

    private string $stdout = '';
    private string $stderr = '';

    public function __construct(
        private readonly Process $process,
        private readonly ?EffectId $effectId,
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
            $this->stopIfTimedOut();
            $this->collectOutput();
            $this->process->clearOutput();
            $this->process->clearErrorOutput();

            if ($this->overLimit()) {
                return $this->limitExceeded();
            }

            if ($this->process->isRunning()) {
                return [];
            }

            // isRunning() also reads pipes, so it may have buffered output after the read above.
            $this->collectOutput();

            if ($this->overLimit()) {
                return $this->limitExceeded();
            }
        } catch (Throwable $error) {
            $this->finished = true;

            return [$this->map(new ErrorMsg($error, ErrorSource::Process, $this->effectId))];
        }

        $this->finished = true;

        return [$this->map(new ProcessResultMsg(
            $this->stdout,
            $this->stderr,
            $this->process->getExitCode() ?? -1,
            $this->process->isSuccessful(),
            $this->effectId,
        ))];
    }

    private function collectOutput(): void
    {
        $this->stdout .= $this->process->getIncrementalOutput();
        $this->stderr .= $this->process->getIncrementalErrorOutput();
    }

    private function overLimit(): bool
    {
        return strlen($this->stdout) + strlen($this->stderr) > $this->maxOutputBytes;
    }

    /**
     * @return list<Message>
     */
    private function limitExceeded(): array
    {
        $this->stop();

        return [$this->map(new ErrorMsg(
            new EffectOutputLimitExceeded($this->maxOutputBytes, 'Process'),
            ErrorSource::Process,
            $this->effectId,
        ))];
    }
}
