<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;

/**
 * Walking a cursor down a list of options and keeping it in view.
 *
 * This half knows nothing about what choosing an option means, so a list that
 * picks one and a list that ticks many can both stand on it.
 *
 * @internal
 */
trait OptionCursor
{
    public function cursorRow(): int
    {
        return $this->rowOffset() + $this->cursor;
    }

    /**
     * A list rests at its ends by default: it runs down the screen and may be long
     * enough that coming round reads as a jump. Say `wrapping()` for one short
     * enough that it reads as a step.
     */
    public function withWrapping(bool $wrapping = true): static
    {
        return $this->cloneWith(wrapping: $wrapping);
    }

    /**
     * Rows this widget draws above its options, such as a label and its spacer.
     */
    protected function rowOffset(): int
    {
        return 0;
    }

    protected function cursorScrollOffset(int $areaHeight): int
    {
        $totalRows = $this->rowOffset() + count($this->options);
        $cursorRow = $this->cursorRow();

        if ($areaHeight > 0 && $totalRows > $areaHeight && $cursorRow >= $areaHeight) {
            return $cursorRow - $areaHeight + 1;
        }

        return 0;
    }

    /**
     * Which option sits under the pointer, or null when the click missed them all.
     */
    protected function optionIndexAt(MouseMsg $message, Rect $area, int $count): ?int
    {
        $index = $message->y - $area->y + $this->cursorScrollOffset($area->height) - $this->rowOffset();

        return $index >= 0 && $index < $count ? $index : null;
    }

    /**
     * Where an arrow leaves the cursor, or null when it has nowhere to go and the
     * message is still going spare.
     */
    protected function walked(int $step, int $count): ?static
    {
        if ($count === 0) {
            return null;
        }

        $moved = $this->wrapping
            ? ($this->cursor + $step + $count) % $count
            : max(0, min($count - 1, $this->cursor + $step));

        return $moved === $this->cursor ? null : $this->cloneWith(cursor: $moved);
    }

    /**
     * The wheel walks the cursor, and the scroll offset derived from it follows.
     * A view position of its own is what these lists do not have yet.
     *
     * @return UpdateResult<static>
     */
    protected function handleWheel(MouseMsg $message, int $count): UpdateResult
    {
        $steps = match ($message->action) {
            MouseAction::WheelUp => -$message->wheelSteps(1),
            MouseAction::WheelDown => $message->wheelSteps(1),
            default => 0,
        };

        if ($steps === 0 || $count === 0) {
            return new UpdateResult($this);
        }

        $moved = max(0, min($count - 1, $this->cursor + $steps));

        return new UpdateResult($moved === $this->cursor ? $this : $this->cloneWith(cursor: $moved));
    }
}
