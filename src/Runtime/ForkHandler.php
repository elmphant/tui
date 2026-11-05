<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Revolt\EventLoop;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
final readonly class ForkHandler implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return $command instanceof ForkCmd;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        if (!$command instanceof ForkCmd) {
            return null;
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
}
