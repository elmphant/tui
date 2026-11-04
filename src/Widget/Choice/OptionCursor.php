<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;

/**
 * Share cursor movement and scrolling independently of single or multiple selection.
 *
 * @internal
 */
trait OptionCursor
{
    public function cursorLine(int $width): int
    {
        return $this->rowOffset() + $this->cursor;
    }

    /**
     * Stop at the ends by default; use withWrapping() for short lists where cycling is useful.
     */
    public function withWrapping(bool $wrapping = true): static
    {
        return $this->cloneWith(wrapping: $wrapping);
    }

    protected function rowOffset(): int
    {
        return 0;
    }

    protected function cursorScrollOffset(int $areaHeight): int
    {
        $count = count($this->options);
        $visibleRows = max(0, $areaHeight - $this->rowOffset());

        if ($visibleRows === 0 || $count <= $visibleRows || $this->cursor < $visibleRows) {
            return 0;
        }

        return $this->cursor - $visibleRows + 1;
    }

    protected function optionIndexAt(MouseMsg $message, Rect $area, int $count): ?int
    {
        $row = $message->y - $area->y - $this->rowOffset();
        $index = $row + $this->cursorScrollOffset($area->height);

        return $row >= 0 && $index < $count ? $index : null;
    }

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
     * Wheel input moves the cursor; scrolling follows it because these lists have no independent view position.
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
