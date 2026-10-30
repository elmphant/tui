<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * Pointer reachability and tab order differ; a component may support one without the other.
 */
enum Navigation
{
    /**
     * Tab stops here and a press lands here.
     */
    case Regular;

    /**
     * Pointer-only: useful for scrolling output panes without adding a tab stop.
     */
    case Pointer;

    /**
     * Nowhere the keyboard goes at all — a pane with nothing in it yet.
     */
    case Never;
}
