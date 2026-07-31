<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

/**
 * Every message reaches the model through one queue and every effect through
 * one dispatch, so these cover the runtime's whole flow — but not what a widget
 * does privately inside its own update().
 */
enum TraceEvent: string
{
    case InputRead = 'input.read';
    case Update = 'update';
    case Command = 'command';
    case Render = 'render';

    /**
     * A frame was due but the frame budget was not yet spent.
     */
    case FrameSkipped = 'frame.skipped';
}
