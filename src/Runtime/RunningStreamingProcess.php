<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessStream;
use Elmphant\Tui\Message\ProcessTiming;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class RunningStreamingProcess implements RunningProcess
{
    use ChildProcessLifecycle;

    /**
     * Bound output per pass because Symfony can read megabytes from a flooding child and delay keyboard input.
     */
    private const MESSAGES_PER_TURN = 64;

    /**
     * @var list<Message>
     */
    private array $heldBack = [];
    private readonly OutputFramer $stdout;
    private readonly OutputFramer $stderr;
    private readonly ?float $startedAt;

    public function __construct(
        private readonly Process $process,
        private readonly ?EffectId $effectId,
        int $outputChunkBytes = 65_536,
        /**
         * @var Closure(Message): Message|null
         */
        private readonly ?Closure $wrap = null,
        bool $framesLines = false,
        private readonly ?Clock $clock = null,
    ) {
        $this->stdout = new OutputFramer($outputChunkBytes, $framesLines);
        $this->stderr = new OutputFramer($outputChunkBytes, $framesLines);
        $this->startedAt = $clock?->now();
    }

    public function pump(): array
    {
        if ($this->finished) {
            return $this->nextBatch([]);
        }

        try {
            $this->stopIfTimedOut();
        } catch (ProcessTimedOutException $timeout) {
            $this->finished = true;

            return $this->nextBatch([
                ...$this->finishOutput(),
                $this->map(new ErrorMsg($timeout, ErrorSource::Process, $this->effectId)),
                $this->finishedMessage(),
            ]);
        }
        if ($this->heldBack !== []) {
            return $this->nextBatch([]);
        }
        $messages = $this->messagesFor($this->stdout->push($this->readStdout()), $this->stderr->push($this->readStderr()));

        if ($this->process->isRunning()) {
            return $this->nextBatch($messages);
        }

        $this->finished = true;

        // isRunning() also reads pipes, so it may have buffered output after the drain above.
        return $this->nextBatch([...$messages, ...$this->finishOutput(), $this->finishedMessage()]);
    }

    public function isRunning(): bool
    {
        return !$this->finished || $this->heldBack !== [];
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<Message>
     */
    private function nextBatch(array $messages): array
    {
        $waiting = [...$this->heldBack, ...$messages];
        $this->heldBack = array_slice($waiting, self::MESSAGES_PER_TURN);

        return array_slice($waiting, 0, self::MESSAGES_PER_TURN);
    }

    /**
     * @return list<Message>
     */
    private function finishOutput(): array
    {
        return $this->messagesFor($this->stdout->finish($this->readStdout()), $this->stderr->finish($this->readStderr()));
    }

    private function readStdout(): string
    {
        $bytes = $this->process->getIncrementalOutput();
        $this->process->clearOutput();

        return $bytes;
    }

    private function readStderr(): string
    {
        $bytes = $this->process->getIncrementalErrorOutput();
        $this->process->clearErrorOutput();

        return $bytes;
    }

    /**
     * @param list<string> $stdout
     * @param list<string> $stderr
     *
     * @return list<Message>
     */
    private function messagesFor(array $stdout, array $stderr): array
    {
        return [
            ...array_map(fn (string $chunk): Message => $this->map(new ProcessOutputMsg($chunk, ProcessStream::Stdout, $this->effectId)), $stdout),
            ...array_map(fn (string $chunk): Message => $this->map(new ProcessOutputMsg($chunk, ProcessStream::Stderr, $this->effectId)), $stderr),
        ];
    }

    private function finishedMessage(): Message
    {
        return $this->map(new ProcessFinishedMsg(
            $this->process->getExitCode(),
            $this->effectId,
            $this->clock === null || $this->startedAt === null ? null : new ProcessTiming($this->startedAt, $this->clock->now()),
        ));
    }
}
