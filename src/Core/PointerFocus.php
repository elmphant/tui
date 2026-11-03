<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Core\Cmd\FocusCmd;

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
        foreach (CmdLeaf::all($command) as $leaf) {
            if ($leaf->command instanceof FocusCmd) {
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
