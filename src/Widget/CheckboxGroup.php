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
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class CheckboxGroup implements Focusable, KeybindingsAware, CursorAware, DropdownContent
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
    public static function new(array $options, string $label = ''): self
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
        return $this->rowOffset() + $this->cursor;
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

    /**
     * A count, never "all": that no box ticked means every source is a filter
     * rule belonging to the application, not to a checkbox group.
     */
    public function caption(): string
    {
        return count($this->checkedValues()) . ' of ' . count($this->options);
    }

    public function hasChoice(): bool
    {
        return $this->checkedValues() !== [];
    }

    public function optionAt(MouseMsg $message, Rect $area): ?string
    {
        if (!$message->inRect($area)) {
            return null;
        }

        $labelRows = $this->rowOffset();
        $row = $message->y - $area->y + $this->scrollOffset($area->height) - $labelRows;

        return $this->options[$row] ?? null;
    }

    public function hasOption(string $option): bool
    {
        return in_array($option, $this->options, true);
    }

    public function activated(string $option): static
    {
        $index = array_search($option, $this->options, true);
        if ($index === false) {
            return $this;
        }

        return $this->isChecked($index)
            ? $this->cloneWith(checked: array_values(array_filter(
                $this->checked,
                static fn (int $checked): bool => $checked !== $index,
            )))
            : $this->cloneWith(checked: [...$this->checked, $index]);
    }

    public function popupWidth(): int
    {
        $widest = 0;
        foreach ($this->options as $option) {
            $widest = max($widest, CharWidth::stringWidth($option));
        }

        return $widest + CharWidth::stringWidth(OptionMarks::CHECKED);
    }

    public function popupHeight(int $width): int
    {
        return $this->rowOffset() + count($this->options);
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
        return Constraint::exact(count($this->options) + $this->rowOffset());
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

        $y = $area->y - $this->scrollOffset($area->height);

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

            $mark = $isChecked ? OptionMarks::CHECKED : OptionMarks::UNCHECKED;

            $style = $theme->optionStyle($isCursor, $isChecked, $this->focused);

            $frame->putStyled($area->x, $y, $mark, $style);
            $frame->putStyled($area->x + CharWidth::stringWidth($mark), $y, $option, $style);
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
        $labelRows = $this->rowOffset();
        $optionY = $message->y - $area->y + $this->scrollOffset($area->height) - $labelRows;

        if ($optionY >= 0 && $optionY < $count) {
            $toggled = $this->cloneWith(cursor: $optionY);

            return new UpdateResult($toggled->toggleCurrent());
        }

        return new UpdateResult($this);
    }

    /**
     * Rows this widget draws above its options — its label and the spacer under it.
     */
    private function rowOffset(): int
    {
        return $this->label !== '' ? 2 : 0;
    }

    private function scrollOffset(int $areaHeight): int
    {
        $labelRows = $this->rowOffset();
        $totalRows = $labelRows + count($this->options);
        $cursorRow = $labelRows + $this->cursor;

        if ($areaHeight > 0 && $totalRows > $areaHeight && $cursorRow >= $areaHeight) {
            return $cursorRow - $areaHeight + 1;
        }

        return 0;
    }
}
