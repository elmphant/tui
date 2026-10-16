<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessStream;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class RunningStreamingProcess implements RunningProcess
{
    use ChildProcessLifecycle;

    /**
     * Symfony reads a pipe for as long as full chunks keep coming, so one read of a flooding child can be megabytes:
     * a key typed meanwhile would queue behind all of it.
     */
    private const MESSAGES_PER_TURN = 64;

    /**
     * @var list<Message>
     */
    private array $heldBack = [];

    public function __construct(
        private readonly Process $process,
        private readonly ?EffectId $effectId,
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
        if ($this->heldBack !== []) {
            return $this->nextBatch([]);
        }

        $this->process->checkTimeout();
        $messages = $this->drainOutput();

        if ($this->process->isRunning()) {
            return $this->nextBatch($messages);
        }

        $this->finished = true;

        // isRunning() reads the pipes into Symfony's own buffer, so a flush the child
        // made between the drain above and this check is only visible now.
        $messages = [...$messages, ...$this->drainOutput()];

        $messages[] = $this->map(new ProcessFinishedMsg(
            $this->process->getExitCode() ?? -1,
            $this->process->isSuccessful(),
            $this->effectId,
        ));

        return $messages;
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
    private function drainOutput(): array
    {
        $messages = [];

        $stdout = $this->process->getIncrementalOutput();
        if ($stdout !== '') {
            foreach (str_split($stdout, max(1, $this->outputChunkBytes)) as $chunk) {
                $messages[] = $this->map(new ProcessOutputMsg($chunk, ProcessStream::Stdout, $this->effectId));
            }
        }
        $this->process->clearOutput();

        $stderr = $this->process->getIncrementalErrorOutput();
        if ($stderr !== '') {
            foreach (str_split($stderr, max(1, $this->outputChunkBytes)) as $chunk) {
                $messages[] = $this->map(new ProcessOutputMsg($chunk, ProcessStream::Stderr, $this->effectId));
            }
        }
        $this->process->clearErrorOutput();

        return $messages;
    }
}
