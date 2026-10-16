<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * A command whose effect answers to a name, so `Cmd::cancel()` can stop it and a
 * second command under the same name replaces it rather than joining it — the
 * built-in effects and a command an application defines for its own
 * `CommandHandler` alike.
 */
interface NamesItsEffect
{
    public function effectId(): ?EffectId;
}
