<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;

/**
 * The cursor-and-selection machinery a Select and a Radio share: a radio is a
 * select drawn with a dot.
 *
 * @internal
 */
trait OptionListBehavior
{
    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_NEXT => ['down'],
            Actions::FOCUS_PREV => ['up'],
            Actions::SELECT => ['enter', 'space'],
        ]);
    }

    public function cursorRow(): int
    {
        return $this->rowOffset() + $this->cursor;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $count = count($this->options);

        return match (true) {
            $message instanceof KeyMsg && $this->focused => $this->handleKey($message, $count),
            $message instanceof MouseMsg && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area, $count),
            $message instanceof MouseMsg && $message->inRect($area) => $this->handleWheel($message, $count),
            default => new UpdateResult($this),
        };
    }

    /**
     * Rows this widget draws above its options — a Radio's label and spacer.
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
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area, int $count): UpdateResult
    {
        $clickedRow = $message->y - $area->y + $this->cursorScrollOffset($area->height);
        $optionIndex = $clickedRow - $this->rowOffset();

        if ($optionIndex >= 0 && $optionIndex < $count) {
            return new UpdateResult($this->cloneWith(cursor: $optionIndex, selected: $optionIndex));
        }

        return new UpdateResult($this);
    }

    /**
     * The wheel walks the cursor, and the scroll offset derived from it follows.
     * A view position of its own is what this list does not have yet.
     *
     * @return UpdateResult<static>
     */
    private function handleWheel(MouseMsg $message, int $count): UpdateResult
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

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message, int $count): UpdateResult
    {
        $bindings = $this->effectiveKeybindings();

        $updated = match (true) {
            $bindings->matches($message, Actions::FOCUS_PREV) => $this->cursor > 0 ? $this->cloneWith(cursor: $this->cursor - 1) : null,
            $bindings->matches($message, Actions::FOCUS_NEXT) => $this->cursor < $count - 1 ? $this->cloneWith(cursor: $this->cursor + 1) : null,
            $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->cursor),
            default => null,
        };

        return new UpdateResult($updated ?? $this);
    }
}
