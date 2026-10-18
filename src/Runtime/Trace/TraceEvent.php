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
     * The keyboard moved. The runtime's own doing, so no update reports it.
     */
    case Focus = 'focus';

    /**
     * A frame was due but the frame budget was not yet spent.
     */
    case FrameSkipped = 'frame.skipped';

    /**
     * A command took over a name an effect was still running under, and stopped it.
     */
    case EffectReplaced = 'effect.replaced';

    /**
     * Something threw, wherever it was: recorded even when the model swallows the ErrorMsg.
     */
    case Error = 'error';

    /**
     * What the app itself asked to have written down, through Cmd::log().
     */
    case Log = 'log';

    /**
     * What the model echoed or dumped. Caught before it reached the terminal,
     * where it would have broken the screen.
     */
    case Output = 'output';
}
