<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Message\MouseMsg;

/**
 * What a press does to the keyboard, as rules about messages and commands
 * rather than about the app holding them.
 *
 * @internal
 */
final class PointerFocus
{
    /**
     * Whether anything that answered the pointer already said where the keyboard
     * goes. Where the pointer landed is only the default, so a more specific
     * answer to the same press must not be raced by it.
     */
    public static function alreadyMoved(?Cmd $command): bool
    {
        if ($command instanceof FocusCmd) {
            return true;
        }

        if ($command instanceof MappedCmd) {
            return self::alreadyMoved($command->inner);
        }

        if (!$command instanceof BatchCmd) {
            return false;
        }

        foreach ($command->commands as $child) {
            if (self::alreadyMoved($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the keyboard goes once the pointer has landed. A press on nothing
     * takes it away; a wheel turn over nothing leaves it where it was.
     */
    public static function after(MouseMsg $message, ?ComponentId $landedOn): ?Cmd
    {
        if ($landedOn !== null) {
            return Cmd::focus($landedOn);
        }

        return $message->isClick() ? Cmd::focusNothing() : null;
    }
}
