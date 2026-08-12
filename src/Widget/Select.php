<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class Select implements Focusable, KeybindingsAware, CursorAware, DropdownContent, Committing
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
        private int $selected = -1,
        private bool $focused = false,
        private string $emptyCaption = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $clearable = false,
        private bool $wrapping = false,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        return new self(options: $options);
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

    public function withEmptyCaption(string $caption): self
    {
        return $this->cloneWith(emptyCaption: $caption);
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

    public function caption(): string
    {
        return $this->options[$this->selected] ?? $this->emptyCaption;
    }

    public function hasChoice(): bool
    {
        return $this->selectedValue() !== null;
    }

    public function popupWidth(): int
    {
        $widest = 0;
        foreach ($this->options as $option) {
            $widest = max($widest, CharWidth::stringWidth($option));
        }

        return $widest;
    }

    /**
     * An empty list still binds enter to SELECT, so the binding alone would
     * close a popup over nothing.
     */
    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            && ($this->options[$this->cursor] ?? null) !== null;
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
        return Constraint::exact(count($this->options));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;
        $count = count($this->options);
        $scrollOffset = $this->cursorScrollOffset($area->height);

        for ($index = $scrollOffset; $index < min($count, $scrollOffset + $area->height); ++$index) {
            $y = $area->y + $index - $scrollOffset;
            $isCursor = $index === $this->cursor && $this->focused;
            $isSelected = $index === $this->selected;

            $style = $theme->optionStyle($isCursor, $isSelected, $this->focused);

            $frame->putStyled($area->x, $y, CharWidth::truncateToWidth($this->options[$index], $area->width), $style);
        }
    }
}
