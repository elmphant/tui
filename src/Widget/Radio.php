<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class Radio implements Focusable, KeybindingsAware, CursorAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    use OptionListBehavior;

    /**
     * @param list<string> $options
     */
    public function __construct(
        private array $options = [],
        private int $cursor = 0,
        private int $selected = 0,
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

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor);
    }

    public function selected(): int
    {
        return $this->selected;
    }

    public function selectedValue(): ?string
    {
        return $this->options[$this->selected] ?? null;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function cursorRow(): int
    {
        return ($this->label !== '' ? 2 : 0) + $this->cursor;
    }

    public function constraint(): Constraint
    {
        $labelHeight = $this->label !== '' ? 2 : 0;

        return Constraint::exact(count($this->options) + $labelHeight);
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
            $frame->putStyled($area->x, $y, CharWidth::truncateToWidth($this->label, $area->width), $theme->titleStyle);
            $y += 2;
        }

        foreach ($this->options as $index => $option) {
            if ($y >= $area->bottom()) {
                break;
            }

            $isCursor = $index === $this->cursor && $this->focused;
            $isSelected = $index === $this->selected;

            $dot = $isSelected ? '● ' : '○ ';

            $style = $theme->optionStyle($isCursor, $isSelected, $this->focused);

            $frame->putStyled($area->x, $y, CharWidth::truncateToWidth($dot, $area->width), $style);
            $frame->putStyled($area->x + 2, $y, CharWidth::truncateToWidth($option, max(0, $area->width - 2)), $style);
            ++$y;
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area, int $count): UpdateResult
    {
        $localY = $message->y - $area->y;
        $optionY = $this->label !== '' ? $localY - 2 : $localY;
        if ($optionY >= 0 && $optionY < $count) {
            return new UpdateResult($this->cloneWith(cursor: $optionY, selected: $optionY));
        }

        return new UpdateResult($this);
    }
}
