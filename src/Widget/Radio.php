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
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class Radio implements Focusable, KeybindingsAware, CursorAware, DropdownContent, Committing
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    use OptionListBehavior;

    public const PICKED_MARK = OptionMarks::PICKED;
    public const FREE_MARK = OptionMarks::FREE;

    /**
     * @param list<string> $options
     */
    public function __construct(
        private array $options = [],
        private int $cursor = 0,
        private int $selected = 0,
        private bool $focused = false,
        private string $label = '',
        private string $emptyCaption = '',
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

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
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

    /**
     * A radio rests on its first option from the start, so the empty caption
     * only ever shows for a radio with nothing to offer.
     */
    public function caption(): string
    {
        return $this->options[$this->selected] ?? $this->emptyCaption;
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

    public function hasChoice(): bool
    {
        return $this->selectedValue() !== null;
    }

    public function activated(string $option): static
    {
        $index = array_search($option, $this->options, true);

        return $index === false ? $this : $this->cloneWith(cursor: $index, selected: $index);
    }

    public function measureWidth(): int
    {
        $widest = 0;
        foreach ($this->options as $option) {
            $widest = max($widest, CharWidth::stringWidth($option));
        }

        return $widest + CharWidth::stringWidth(self::PICKED_MARK);
    }

    public function measureHeight(int $width): int
    {
        return $this->rowOffset() + count($this->options);
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
        return Constraint::exact(count($this->options) + $this->rowOffset());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;

        $y = $area->y - $this->cursorScrollOffset($area->height);

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

            $mark = $isSelected ? self::PICKED_MARK : self::FREE_MARK;
            $markWidth = CharWidth::stringWidth($mark);

            $style = $theme->optionStyle($isCursor, $isSelected, $this->focused);

            $frame->putStyled($area->x, $y, CharWidth::truncateToWidth($mark, $area->width), $style);
            $frame->putStyled($area->x + $markWidth, $y, CharWidth::truncateToWidth($option, max(0, $area->width - $markWidth)), $style);
            ++$y;
        }
    }

    protected function rowOffset(): int
    {
        return $this->label !== '' ? 2 : 0;
    }
}
