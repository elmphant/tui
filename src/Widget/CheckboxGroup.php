<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class CheckboxGroup implements Focusable, KeybindingsAware, CursorAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    /**
     * @param list<string> $options
     * @param list<int>    $checked
     */
    public function __construct(
        private array $options = [],
        private int $cursor = 0,
        private array $checked = [],
        private bool $focused = false,
        private string $label = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(string $label, array $options): self
    {
        return new self(options: $options, label: $label);
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): self
    {
        return $this->cloneWith(options: $options);
    }

    /**
     * @param list<int> $checked
     */
    public function withChecked(array $checked): self
    {
        return $this->cloneWith(checked: $checked);
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_NEXT => ['down'],
            Actions::FOCUS_PREV => ['up'],
            Actions::TOGGLE => ['enter', 'space'],
        ]);
    }

    public function cursorRow(): int
    {
        return ($this->label !== '' ? 2 : 0) + $this->cursor;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor);
    }

    public function isChecked(int $index): bool
    {
        return in_array($index, $this->checked, true);
    }

    /**
     * @return list<string>
     */
    public function checkedValues(): array
    {
        $values = [];
        foreach ($this->checked as $index) {
            if (isset($this->options[$index])) {
                $values[] = $this->options[$index];
            }
        }

        return $values;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function constraint(): Constraint
    {
        $labelHeight = $this->label !== '' ? 2 : 0;

        return Constraint::exact(count($this->options) + $labelHeight);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $count = count($this->options);

        return match (true) {
            $message instanceof KeyMsg && $this->focused => $this->handleKey($message, $count),
            $message instanceof MouseMsg && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area, $count),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;

        $labelRows = $this->label !== '' ? 2 : 0;
        $totalRows = $labelRows + count($this->options);
        $cursorRow = $labelRows + $this->cursor;

        $scrollOffset = 0;
        if ($area->height > 0 && $totalRows > $area->height && $cursorRow >= $area->height) {
            $scrollOffset = $cursorRow - $area->height + 1;
        }

        $y = $area->y - $scrollOffset;

        if ($this->label !== '') {
            $frame->putStyled($area->x, $y, $this->label, $theme->titleStyle);
            $y += 2;
        }

        foreach ($this->options as $index => $option) {
            if ($y >= $area->bottom()) {
                break;
            }

            $isCursor = $index === $this->cursor && $this->focused;
            $isChecked = $this->isChecked($index);

            $mark = $isChecked ? '[x] ' : '[ ] ';

            $style = $theme->optionStyle($isCursor, $isChecked, $this->focused);

            $frame->putStyled($area->x, $y, $mark, $style);
            $frame->putStyled($area->x + 4, $y, $option, $style);
            ++$y;
        }
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
            $bindings->matches($message, Actions::TOGGLE) => $this->toggleCurrent(),
            default => null,
        };

        return new UpdateResult($updated ?? $this);
    }

    private function toggleCurrent(): self
    {
        if ($this->isChecked($this->cursor)) {
            return $this->cloneWith(checked: array_values(array_filter(
                $this->checked,
                fn (int $index) => $index !== $this->cursor,
            )));
        }

        return $this->cloneWith(checked: [...$this->checked, $this->cursor]);
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area, int $count): UpdateResult
    {
        $localY = $message->y - $area->y;
        $optionY = $this->label !== '' ? $localY - 2 : $localY;

        if ($optionY >= 0 && $optionY < $count) {
            $toggled = $this->cloneWith(cursor: $optionY);

            return new UpdateResult($toggled->toggleCurrent());
        }

        return new UpdateResult($this);
    }
}
