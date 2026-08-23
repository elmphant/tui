<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\FloatPlacement;
use Elmphant\Tui\Widget\Popup\PointerFloating;

/**
 * A pointer event that landed on a float the last frame drew.
 *
 * Composed rather than copying MouseMsg's fields: isClick()/wheelSteps()/inRect()
 * keep their one definition on MouseMsg, and a future MouseMsg field cannot be
 * dropped here by omission.
 */
final readonly class FloatMouseMsg implements Message
{
    /**
     * @internal the runtime mints these; an application receives them
     */
    public function __construct(
        public MouseMsg $mouse,
        private FloatPlacement $float,
    ) {
    }

    public function popup(): PointerFloating
    {
        return $this->float->float;
    }

    /**
     * Where the float was drawn last frame, which is what the reader pointed at;
     * the content may have moved on since.
     */
    public function drawnIn(): Rect
    {
        return $this->float->placement;
    }
}
