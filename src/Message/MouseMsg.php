<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Support\Cloneable;

final readonly class MouseMsg implements Message
{
    use Cloneable;

    /**
     * @param int  $repeat          how many notches this event stands for. A terminal
     *                              sends one event per notch, so the runtime merges a
     *                              run of them into a single message.
     * @param bool $keyboardWasHere a press reaches every component under it, and the
     *                              one it lands on is also the one it moves the
     *                              keyboard to, so a widget that acts only where the
     *                              reader already is cannot tell the arriving press
     *                              from the acting one without being told
     */
    public function __construct(
        public int $x,
        public int $y,
        public MouseAction $action,
        public MouseButton $button,
        public int $repeat = 1,
        public ?Rect $screen = null,
        public bool $shift = false,
        public bool $alt = false,
        public bool $ctrl = false,
        public bool $keyboardWasHere = false,
    ) {
    }

    public function withScreen(Rect $screen): self
    {
        return $this->cloneWith(screen: $screen);
    }

    public function withKeyboardHere(bool $here): self
    {
        return $this->cloneWith(keyboardWasHere: $here);
    }

    public function withRepeat(int $repeat): self
    {
        return $this->cloneWith(repeat: $repeat);
    }

    /**
     * Include merged wheel notches in the scroll distance; the default step is one row per notch.
     */
    public function wheelSteps(int $step = 1): int
    {
        return $step * max(1, $this->repeat);
    }

    public function inRect(Rect $rect): bool
    {
        return $rect->contains($this->x, $this->y);
    }

    public function isClick(): bool
    {
        return $this->action === MouseAction::Press && $this->button !== MouseButton::None;
    }

    public function isWheel(): bool
    {
        return match ($this->action) {
            MouseAction::WheelUp, MouseAction::WheelDown, MouseAction::WheelLeft, MouseAction::WheelRight => true,
            default => false,
        };
    }

    public function isDrag(): bool
    {
        return $this->action === MouseAction::Motion && $this->button !== MouseButton::None;
    }
}
