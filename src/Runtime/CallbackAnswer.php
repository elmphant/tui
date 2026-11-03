<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
final class CallbackAnswer
{
    /**
     * @param Closure(): mixed $callback
     */
    public static function of(Closure $callback, ErrorSource $source, string $factory, ?EffectId $id = null): Cmd|Message|null
    {
        try {
            $answer = $callback();
        } catch (Throwable $error) {
            return new ErrorMsg($error, $source, $id);
        }

        return $answer instanceof Cmd || $answer instanceof Message || $answer === null
            ? $answer
            : new ErrorMsg(new UnexpectedValueException(sprintf('%s callback must return Message, Cmd, or null', $factory)), $source, $id);
    }
}
