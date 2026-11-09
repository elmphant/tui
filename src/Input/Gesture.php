<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\MouseMsg;

/**
 * What a widget does with the mouse, checked with matches() and named in hints like a key.
 */
enum Gesture
{
    case Click;
    case Drag;
    case Wheel;

    public function matches(MouseMsg $message): bool
    {
        return match ($this) {
            self::Click => $message->isClick(),
            self::Drag => $message->isDrag(),
            self::Wheel => $message->isWheel(),
        };
    }

    public function label(): string
    {
        return $this->name;
    }
}
