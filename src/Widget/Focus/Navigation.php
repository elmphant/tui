<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * Where a focusable is willing to be reached from. The keyboard can be somewhere
 * without tab stopping there, which is why this is not a yes or no.
 */
enum Navigation
{
    /**
     * Tab stops here and a press lands here.
     */
    case Regular;

    /**
     * A press lands here, tab walks past: an output pane worth clicking into to
     * scroll, but not worth a stop between the panes a reader works in.
     */
    case Pointer;

    /**
     * Nowhere the keyboard goes at all — a pane with nothing in it yet.
     */
    case Never;
}
