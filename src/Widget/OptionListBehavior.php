<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;

/**
 * The cursor-and-selection machinery a Select and a Radio share: a radio is a
 * select drawn with a dot.
 *
 * @internal
 */
trait OptionListBehavior
{
    use OptionCursor;

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_NEXT => ['down'],
            Actions::FOCUS_PREV => ['up'],
            Actions::SELECT => ['enter', 'space'],
        ]);
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
     * For a choice that may also be none: pressing the option already picked puts
     * it back down rather than picking it again.
     *
     * Off by default, because a list that must answer should not be emptiable by a
     * stray keypress. Nothing picked is a state these widgets can already hold and
     * draw — this is how a reader reaches it.
     */
    public function clearable(): static
    {
        return $this->cloneWith(clearable: true);
    }

    public function optionAt(MouseMsg $message, Rect $area): ?string
    {
        if (!$message->inRect($area)) {
            return null;
        }

        $row = $message->y - $area->y + $this->cursorScrollOffset($area->height) - $this->rowOffset();

        return $this->options[$row] ?? null;
    }

    public function hasOption(string $option): bool
    {
        return in_array($option, $this->options, true);
    }

    public function popupHeight(int $width): int
    {
        return $this->rowOffset() + count($this->options);
    }

    /**
     * A pointer pick lands where a key press lands, clearable list included.
     */
    public function activated(string $option): static
    {
        $index = array_search($option, $this->options, true);

        return $index === false ? $this : $this->cloneWith(cursor: $index, selected: $this->picking($index));
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area, int $count): UpdateResult
    {
        $optionIndex = $this->optionIndexAt($message, $area, $count);

        if ($optionIndex === null) {
            return new UpdateResult($this);
        }

        return new UpdateResult($this->cloneWith(
            cursor: $optionIndex,
            selected: $this->picking($optionIndex),
        ));
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message, int $count): UpdateResult
    {
        $bindings = $this->effectiveKeybindings();

        $updated = match (true) {
            $bindings->matches($message, Actions::FOCUS_PREV) => $this->walked(-1, $count),
            $bindings->matches($message, Actions::FOCUS_NEXT) => $this->walked(1, $count),
            $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->picking($this->cursor)),
            default => null,
        };

        return new UpdateResult($updated ?? $this);
    }

    /**
     * What a press lands on: the option it went to, or nothing at all when that is
     * the one already picked and this list may be cleared.
     */
    private function picking(int $index): int
    {
        return $this->clearable && $this->selected === $index ? -1 : $index;
    }
}
