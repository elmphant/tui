<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;

/**
 * Wrap MouseMsg instead of copying its fields so mouse helpers stay shared and new fields cannot be lost.
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
     * Use the last drawn placement for clicks, since the content may have moved since that frame.
     */
    public function drawnIn(): Rect
    {
        return $this->float->placement;
    }
}
