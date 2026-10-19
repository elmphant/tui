<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\Keybindings;

/**
 * A model, or a mounted sub-model, with bindings of more than one key. The
 * runtime holds the keys of a chord back until it completes, then hands the model
 * its last key carrying the rest.
 */
interface BindsChords
{
    /**
     * The chords that apply while the keyboard is at $focus. A mounted model is
     * asked with the focus named inside it.
     */
    public function chords(FocusState $focus): Keybindings;
}
