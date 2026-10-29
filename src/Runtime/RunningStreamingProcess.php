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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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
    private string $pendingStdout = '';
    private string $pendingStderr = '';
    private readonly ?float $startedAt;

    public function __construct(
        private readonly Process $process,
        private readonly ?EffectId $effectId,
        private readonly int $outputChunkBytes = 65_536,
        /**
         * @var Closure(Message): Message|null
         */
        private readonly ?Closure $wrap = null,
        private readonly bool $framesLines = false,
        private readonly ?Clock $clock = null,
    ) {
        $this->startedAt = $clock?->now();
    }

    public function pump(): array
    {
        if ($this->finished) {
            return [];
        }
        if ($this->heldBack !== []) {
            return $this->nextBatch([]);
        }

        try {
            $this->process->checkTimeout();
        } catch (ProcessTimedOutException $timeout) {
            $this->finished = true;

            return [
                ...$this->drainOutput(ending: true),
                $this->map(new ErrorMsg($timeout, ErrorSource::Process, $this->effectId)),
                $this->finishedMessage(),
            ];
        }
        $messages = $this->drainOutput(ending: false);

        if ($this->process->isRunning()) {
            return $this->nextBatch($messages);
        }

        $this->finished = true;

        // isRunning() reads the pipes into Symfony's own buffer, so a flush the child
        // made between the drain above and this check is only visible now.
        return [...$messages, ...$this->drainOutput(ending: true), $this->finishedMessage()];
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

    private function finishedMessage(): Message
    {
        return $this->map(new ProcessFinishedMsg(
            $this->process->getExitCode() ?? -1,
            $this->process->isSuccessful(),
            $this->effectId,
            $this->startedAt,
            $this->clock?->now(),
        ));
    }

    /**
     * @return list<Message>
     */
    private function drainOutput(bool $ending): array
    {
        $stdout = $this->pendingStdout . $this->process->getIncrementalOutput();
        $this->process->clearOutput();
        $stderr = $this->pendingStderr . $this->process->getIncrementalErrorOutput();
        $this->process->clearErrorOutput();

        [$stdout, $this->pendingStdout] = $ending ? [$stdout, ''] : $this->readyAndPending($stdout);
        [$stderr, $this->pendingStderr] = $ending ? [$stderr, ''] : $this->readyAndPending($stderr);

        return [...$this->messagesFor($stdout, ProcessStream::Stdout), ...$this->messagesFor($stderr, ProcessStream::Stderr)];
    }

    /**
     * @return array{string, string}
     */
    private function readyAndPending(string $text): array
    {
        $ready = $this->framesLines ? self::wholeLinesLength($text) : self::wholeCharactersLength($text);

        return [substr($text, 0, $ready), substr($text, $ready)];
    }

    private static function wholeLinesLength(string $text): int
    {
        $lastNewline = strrpos($text, "\n");

        return $lastNewline === false ? 0 : $lastNewline + 1;
    }

    private static function wholeCharactersLength(string $text): int
    {
        $length = \strlen($text);
        $start = $length - 1;
        while ($start >= 0 && $start > $length - 4 && self::isContinuationByte($text[$start])) {
            --$start;
        }
        if ($start < 0) {
            return $length;
        }

        $lead = \ord($text[$start]);
        $sequenceLength = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default => 1,
        };

        return $start + $sequenceLength > $length ? $start : $length;
    }

    /**
     * @return list<Message>
     */
    private function messagesFor(string $text, ProcessStream $stream): array
    {
        if ($text === '') {
            return [];
        }

        $chunks = $this->framesLines ? [$text] : self::chunksOf($text, max(1, $this->outputChunkBytes));

        return array_map(
            fn (string $chunk): Message => $this->map(new ProcessOutputMsg($chunk, $stream, $this->effectId)),
            $chunks,
        );
    }

    /**
     * @return list<string>
     */
    private static function chunksOf(string $text, int $size): array
    {
        $chunks = [];
        while ($text !== '') {
            $length = \strlen($text);
            $cut = min($size, $length);
            while ($cut > 0 && $cut < $length && self::isContinuationByte($text[$cut])) {
                --$cut;
            }
            if ($cut === 0) {
                $cut = $size;
                while ($cut < $length && self::isContinuationByte($text[$cut])) {
                    ++$cut;
                }
            }
            $chunks[] = substr($text, 0, $cut);
            $text = substr($text, $cut);
        }

        return $chunks;
    }

    private static function isContinuationByte(string $byte): bool
    {
        return (\ord($byte) & 0xC0) === 0x80;
    }
}
