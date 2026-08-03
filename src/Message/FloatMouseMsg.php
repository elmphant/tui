<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Rendering\FloatPlacement;

/**
 * Composed rather than copying MouseMsg's fields: isClick()/wheelSteps()/inRect()
 * keep their one definition on MouseMsg, and a future MouseMsg field cannot be
 * dropped here by omission.
 */
final readonly class FloatMouseMsg implements Message
{
    public function __construct(
        public MouseMsg $mouse,
        public FloatPlacement $float,
    ) {
    }
}
