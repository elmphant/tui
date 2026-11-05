<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\ForkedWorkFailed;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
final readonly class ForkHandler implements CommandHandler
{
    private bool $forks;

    public function __construct(?bool $forks = null)
    {
        $this->forks = $forks ?? Cmd::canFork();
    }

    public function handles(Cmd $command): bool
    {
        return $command instanceof ForkCmd;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        if (!$command instanceof ForkCmd) {
            return null;
        }

        $running = $this->forks ? RunningFork::start($command) : null;
        if ($running !== null) {
            return self::listenTo($running, $command, $emit, $finished);
        }

        EventLoop::queue(static function () use ($command, $emit, $finished): void {
            $answer = self::answerOf($command, $emit);
            if ($answer !== null) {
                $emit($answer);
            }
            $finished();
        });

        return static function (): void {
        };
    }

    /**
     * @param Closure(Message): void $send
     */
    public static function answerOf(ForkCmd $command, Closure $send): ?Message
    {
        try {
            $result = ($command->work)($send);
        } catch (Throwable $error) {
            return new ErrorMsg($error, ErrorSource::Fork, $command->id);
        }

        if ($result !== null && !$result instanceof Message) {
            return new ErrorMsg(new UnexpectedValueException('Cmd::fork() work must return a Message or null'), ErrorSource::Fork, $command->id);
        }

        return $result;
    }

    /**
     * @param Closure(Message): void $emit
     * @param Closure(): void        $finished
     *
     * @return Closure(): void
     */
    private static function listenTo(RunningFork $running, ForkCmd $command, Closure $emit, Closure $finished): Closure
    {
        $running->watcherId = EventLoop::onReadable($running->socket, static function () use ($running, $command, $emit, $finished): void {
            foreach ($running->read() as [$frame, $payload]) {
                if ($frame === ForkFrame::End) {
                    $running->close();
                    $finished();

                    return;
                }

                $emit(self::messageIn($frame, $payload, $command));
            }

            if ($running->endedWithoutItsLastFrame()) {
                $running->close();
                $emit(new ErrorMsg(new RuntimeException('Cmd::fork() work ended without an answer'), ErrorSource::Fork, $command->id));
                $finished();
            }
        });

        return $running->kill(...);
    }

    private static function messageIn(ForkFrame $frame, string $payload, ForkCmd $command): Message
    {
        $said = unserialize($payload);

        if ($frame === ForkFrame::Failure && \is_array($said) && \count($said) === 3) {
            [$class, $message, $trace] = array_map(static fn (mixed $part): string => \is_string($part) ? $part : '', $said);

            return new ErrorMsg(new ForkedWorkFailed($message, $class, $trace), ErrorSource::Fork, $command->id);
        }

        return $said instanceof Message
            ? $said
            : new ErrorMsg(new UnexpectedValueException('Cmd::fork() work sent something that is not a Message'), ErrorSource::Fork, $command->id);
    }
}
