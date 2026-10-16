<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Popup\DropdownContent;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class Radio implements Focusable, KeybindingsAware, CursorAware, DropdownContent, Committing, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    use OptionListBehavior;

    public const SELECTED_MARK = OptionMarks::SELECTED;
    public const UNSELECTED_MARK = OptionMarks::UNSELECTED;

    /**
     * @param list<string> $options
     */
    private function __construct(
        private array $options = [],
        private int $cursor = 0,
        private int $selected = 0,
        private string $label = '',
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
    public static function new(array $options, string $label = ''): self
    {
        Options::assertDistinct($options, 'Radio');

        return new self(options: $options, label: $label);
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): self
    {
        Options::assertDistinct($options, 'Radio');

        if ($options === $this->options) {
            return $this;
        }

        // A radio always stands on something, so an option that has gone hands the
        // choice to the first rather than to none.
        return $this->cloneWith(
            options: $options,
            selected: max(0, Options::placeOf($this->selectedValue(), $options)),
            cursor: Options::cursorAfter($this->options[$this->cursor] ?? null, $options),
        );
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    /**
     * A host holds what was chosen, not where it sat. A radio always stands on
     * something, so a value it does not have leaves it on the first.
     */
    public function withSelectedValue(?string $value): self
    {
        return $this->cloneWith(selected: max(0, Options::placeOf($value, $this->options)));
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

    public function selectedIndex(): int
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

        return $widest + CharWidth::stringWidth(self::SELECTED_MARK);
    }

    /**
     * An empty list still binds enter to SELECT, so the binding alone would
     * close a popup over nothing.
     */
    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(max($this->popupWidth(), CharWidth::stringWidth($this->label))),
            Constraint::exact(count($this->options) + $this->rowOffset()),
        );
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;
        $count = count($this->options);
        $scrollOffset = $this->cursorScrollOffset($area->height);

        $y = $area->y;

        if ($this->label !== '') {
            $frame->putStyled($area->x, $y, CharWidth::truncateToWidth($this->label, $area->width), $theme->titleStyle);
            $y += 2;
        }

        for ($index = $scrollOffset; $index < min($count, $scrollOffset + $area->bottom() - $y); ++$index) {
            $rowY = $y + $index - $scrollOffset;
            $isCursor = $index === $this->cursor && $this->drawsFocused($context);
            $isSelected = $index === $this->selected;

            $mark = $isSelected ? self::SELECTED_MARK : self::UNSELECTED_MARK;
            $markWidth = CharWidth::stringWidth($mark);

            $style = $theme->optionStyle($isCursor, $isSelected, $this->drawsFocused($context));

            $frame->putStyled($area->x, $rowY, CharWidth::truncateToWidth($mark, $area->width), $style);
            $frame->putStyled($area->x + $markWidth, $rowY, CharWidth::truncateToWidth($this->options[$index], max(0, $area->width - $markWidth)), $style);
        }
    }

    protected function rowOffset(): int
    {
        return $this->label !== '' ? 2 : 0;
    }
}
