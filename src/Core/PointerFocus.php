<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;

/**
 * @internal
 */
final class PointerFocus
{
    /**
     * Explicit focus commands take priority over the default focus change caused by a click.
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
     * Clicking empty space preserves focus; clicking the focused component avoids an arrival that would reset scrolling.
     */
    public static function after(?ComponentId $landedOn, ?ComponentId $holder): ?Cmd
    {
        return $landedOn === null || ($holder !== null && $landedOn->equals($holder)) ? null : Cmd::focus($landedOn);
    }
}
