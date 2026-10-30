<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;

/**
 * Combine wheel notches so trackpad events opposite to the gesture cancel out.
 *
 * @internal
 */
final class PendingWheel
{
    private int $notches = 0;

    private ?MouseMsg $last = null;

    public function add(MouseMsg $message): void
    {
        $this->notches += ($message->action === MouseAction::WheelDown ? 1 : -1) * $message->repeat;
        $this->last = $message;
    }

    public function isEmpty(): bool
    {
        return $this->last === null;
    }

    public function take(): ?MouseMsg
    {
        $last = $this->last;
        $notches = $this->notches;

        $this->last = null;
        $this->notches = 0;

        if ($last === null || $notches === 0) {
            return null;
        }

        return new MouseMsg(
            $last->x,
            $last->y,
            $notches > 0 ? MouseAction::WheelDown : MouseAction::WheelUp,
            $last->button,
            abs($notches),
            $last->screen,
        );
    }
}
