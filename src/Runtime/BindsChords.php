<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\Keybindings;

/**
 * Buffer multi-key chords in the runtime, then deliver the final key with the completed chord.
 */
interface BindsChords
{
    /**
     * Resolve chords for the current focus; mounted models receive IDs local to their mount.
     */
    public function chords(FocusState $focus): Keybindings;
}
