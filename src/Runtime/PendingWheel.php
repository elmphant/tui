<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;

/**
 * Wheel notches that have arrived but not been applied yet, as one total.
 *
 * A trackpad reports a speed the terminal can only send as whole notches, so a
 * slow gesture crosses the threshold both ways and the stream alternates.
 *
 * @internal
 */
final class PendingWheel
{
    private int $notches = 0;

    private ?MouseMsg $last = null;

    public function add(MouseMsg $message): void
    {
        $step = match ($message->action) {
            MouseAction::WheelDown => 1,
            MouseAction::WheelUp => -1,
            default => 0,
        };

        if ($step === 0) {
            return;
        }

        $this->notches += $step * max(1, $message->repeat);
        $this->last = $message;
    }

    public function isEmpty(): bool
    {
        return $this->last === null;
    }

    /**
     * What the notches come to, or null when they cancelled out.
     */
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
