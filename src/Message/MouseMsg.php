<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Layout\Rect;

final readonly class MouseMsg implements Message
{
    /**
     * @param int $repeat how many notches this event stands for. A terminal
     *                    sends one event per notch, so the runtime merges a run
     *                    of them into a single message.
     */
    public function __construct(
        public int $x,
        public int $y,
        public MouseAction $action,
        public MouseButton $button,
        public int $repeat = 1,
        public ?Rect $screen = null,
    ) {
    }

    public function withScreen(Rect $screen): self
    {
        return new self($this->x, $this->y, $this->action, $this->button, $this->repeat, $screen);
    }

    public function withRepeat(int $repeat): self
    {
        return new self($this->x, $this->y, $this->action, $this->button, $repeat, $this->screen);
    }

    /**
     * How far to scroll for this event. Always use this instead of the step on
     * its own: one event can stand for many notches.
     */
    public function wheelSteps(int $step): int
    {
        return $step * max(1, $this->repeat);
    }

    public function mergedWith(self $next): self
    {
        return $next->withRepeat($this->repeat + $next->repeat);
    }

    public function inRect(Rect $rect): bool
    {
        return $rect->contains($this->x, $this->y);
    }

    public function isClick(): bool
    {
        return $this->action === MouseAction::Press && $this->button !== MouseButton::None;
    }

    public function isDrag(): bool
    {
        return $this->action === MouseAction::Motion && $this->button !== MouseButton::None;
    }
}
