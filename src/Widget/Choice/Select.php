<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Popup\DropdownContent;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class Select implements Focusable, KeybindingsAware, CursorAware, DropdownContent, Committing, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    use OptionListBehavior;

    /**
     * @param list<string> $options
     */
    private function __construct(
        private array $options = [],
        private int $cursor = 0,
        private int $selected = -1,
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
        Options::assertDistinct($options, 'Select');

        return new self(options: $options);
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): self
    {
        Options::assertDistinct($options, 'Select');

        if ($options === $this->options) {
            return $this;
        }

        return $this->cloneWith(
            options: $options,
            selected: Options::placeOf($this->selectedValue(), $options),
            cursor: Options::cursorAfter($this->options[$this->cursor] ?? null, $options),
        );
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    /**
     * A host holds what was chosen, not where it sat — so it says which option
     * rather than searching the list for its place first.
     */
    public function withSelectedValue(?string $value): self
    {
        return $this->cloneWith(selected: Options::placeOf($value, $this->options));
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
    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis($this->popupWidth()),
            Constraint::exact(count($this->options)),
        );
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;
        $count = count($this->options);
        $scrollOffset = $this->cursorScrollOffset($area->height);

        for ($index = $scrollOffset; $index < min($count, $scrollOffset + $area->height); ++$index) {
            $y = $area->y + $index - $scrollOffset;
            $isCursor = $index === $this->cursor && $this->drawsFocused($context);
            $isSelected = $index === $this->selected;

            $style = $theme->optionStyle($isCursor, $isSelected, $this->drawsFocused($context));

            $frame->putStyled($area->x, $y, CharWidth::truncateToWidth($this->options[$index], $area->width), $style);
        }
    }
}
