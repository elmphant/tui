<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\FiberCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Revolt\EventLoop;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
final readonly class FiberHandler implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return $command instanceof FiberCmd;
    }

    /**
     * A fiber cannot be stopped from outside: a cancelled one runs on, and the runtime drops what it says.
     */
    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        if (!$command instanceof FiberCmd) {
            return null;
        }

        EventLoop::queue(static function () use ($command, $emit, $finished): void {
            $answer = self::answerOf($command);
            if ($answer !== null) {
                $emit($answer);
            }
            $finished();
        });

        return static function (): void {
        };
    }

    /**
     * An exception that escapes a loop callback stops the whole loop, so every one becomes a message.
     */
    public static function answerOf(FiberCmd $command): ?Message
    {
        try {
            $result = ($command->work)();
        } catch (Throwable $error) {
            return new ErrorMsg($error, ErrorSource::Fiber, $command->id);
        }

        if ($result !== null && !$result instanceof Message) {
            return new ErrorMsg(new UnexpectedValueException('Cmd::fiber() work must return a Message or null'), ErrorSource::Fiber, $command->id);
        }

        return $result;
    }
}
