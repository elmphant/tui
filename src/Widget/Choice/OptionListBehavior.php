<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;

/**
 * @internal
 */
trait OptionListBehavior
{
    use OptionCursor;

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_DOWN => ['down'],
            Actions::CURSOR_UP => ['up'],
            Actions::SELECT => ['enter', 'space'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'choose' => Actions::SELECT,
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $count = count($this->options);

        return match (true) {
            $message instanceof KeyMsg => $this->handleKey($message, $count),
            $message instanceof MouseMsg && $message->isClick() && $message->isIn($area) => $this->handleClick($message, $area, $count),
            $message instanceof MouseMsg && $message->isIn($area) => $this->handleWheel($message, $count),
            default => new UpdateResult($this),
        };
    }

    /**
     * Allow selecting the current option to clear it; disabled by default to prevent accidental empty choices.
     */
    public function withClearable(bool $clearable = true): static
    {
        return $this->cloneWith(clearable: $clearable);
    }

    public function optionAt(MouseMsg $message, Rect $area): ?string
    {
        if (!$message->isIn($area)) {
            return null;
        }

        $index = $this->optionIndexAt($message, $area, count($this->options));

        return $index === null ? null : $this->options[$index];
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
     * A SELECT binding alone is not a commit: an empty list must not close its popup.
     */
    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            && ($this->options[$this->cursor] ?? null) !== null;
    }

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
            $bindings->matches($message, Actions::CURSOR_UP) => $this->walked(-1, $count),
            $bindings->matches($message, Actions::CURSOR_DOWN) => $this->walked(1, $count),
            $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->picking($this->cursor)),
            default => null,
        };

        return new UpdateResult($updated ?? $this);
    }

    private function picking(int $index): ?int
    {
        return $this->clearable && $this->selected === $index ? null : $index;
    }
}
