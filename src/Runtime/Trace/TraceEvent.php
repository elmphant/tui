<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

/**
 * Trace the runtime's message and effect flow, excluding work internal to widget updates.
 */
enum TraceEvent: string
{
    case InputRead = 'input.read';
    case Update = 'update';
    case Command = 'command';
    case Render = 'render';

    case Focus = 'focus';

    case FrameSkipped = 'frame.skipped';

    case EffectReplaced = 'effect.replaced';

    case Error = 'error';

    case Log = 'log';

    /**
     * Capture stray model output before it corrupts the terminal display.
     */
    case Output = 'output';
}
