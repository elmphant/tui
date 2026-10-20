<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;

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

        if ($command instanceof MappedCmd || $command instanceof ScopedCmd) {
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
     * Where the keyboard goes once the pointer has landed. Landing on nothing
     * leaves it where it was: a press on a plain row of a form must not strand
     * the reader without a field until they press tab. Landing where it already
     * is leaves it too: an arrival would scroll the panes around it back to it.
     */
    public static function after(?ComponentId $landedOn, ?ComponentId $holder): ?Cmd
    {
        return $landedOn === null || ($holder !== null && $landedOn->equals($holder)) ? null : Cmd::focus($landedOn);
    }
}
