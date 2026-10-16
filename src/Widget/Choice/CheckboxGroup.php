<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Popup\DropdownContent;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class CheckboxGroup implements Focusable, KeybindingsAware, CursorAware, DropdownContent, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use OptionCursor;
    use KeybindingsAwareBehavior;

    /**
     * @param list<string> $options
     * @param list<int>    $checked
     */
    private function __construct(
        private array $options = [],
        private int $cursor = 0,
        private array $checked = [],
        private string $label = '',
        private string $emptyCaption = '',
        private bool $wrapping = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options, string $label = ''): self
    {
        Options::assertDistinct($options, 'CheckboxGroup');

        return new self(options: $options, label: $label);
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): self
    {
        Options::assertDistinct($options, 'CheckboxGroup');

        if ($options === $this->options) {
            return $this;
        }

        $checked = [];
        foreach ($this->checkedValues() as $value) {
            $place = Options::placeOf($value, $options);

            if ($place >= 0) {
                $checked[] = $place;
            }
        }
        sort($checked);

        return $this->cloneWith(
            options: $options,
            checked: $checked,
            cursor: Options::cursorAfter($this->options[$this->cursor] ?? null, $options),
        );
    }

    /**
     * @param list<int> $checked
     */
    public function withChecked(array $checked): self
    {
        return $this->cloneWith(checked: $checked);
    }

    /**
     * A host holds what was ticked, not where the ticks sat.
     *
     * @param list<string> $values
     */
    public function withCheckedValues(array $values): self
    {
        $checked = [];
        foreach ($values as $value) {
            $place = Options::placeOf($value, $this->options);

            if ($place >= 0) {
                $checked[] = $place;
            }
        }
        sort($checked);

        return $this->cloneWith(checked: $checked);
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    public function withEmptyCaption(string $caption): self
    {
        return $this->cloneWith(emptyCaption: $caption);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_DOWN => ['down'],
            Actions::CURSOR_UP => ['up'],
            Actions::TOGGLE => ['enter', 'space'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'toggle' => Actions::TOGGLE,
        ]);
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
     * A count of the ticks. What no tick at all means is the host's to say: a
     * group deciding on its own that none reads as "all" would be inventing a
     * filter rule it knows nothing about.
     */
    public function caption(): string
    {
        if (!$this->hasChoice() && $this->emptyCaption !== '') {
            return $this->emptyCaption;
        }

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

        $index = $this->optionIndexAt($message, $area, count($this->options));

        return $index === null ? null : $this->options[$index];
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

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(max($this->popupWidth(), CharWidth::stringWidth($this->label))),
            Constraint::exact(count($this->options) + $this->rowOffset()),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $count = count($this->options);

        return match (true) {
            $message instanceof KeyMsg => $this->handleKey($message, $count),
            $message instanceof MouseMsg && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area, $count),
            $message instanceof MouseMsg && $message->inRect($area) => $this->handleWheel($message, $count),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;
        $count = count($this->options);
        $scrollOffset = $this->cursorScrollOffset($area->height);

        $y = $area->y;

        if ($this->label !== '') {
            $frame->putStyled($area->x, $y, $this->label, $theme->titleStyle);
            $y += 2;
        }

        for ($index = $scrollOffset; $index < min($count, $scrollOffset + $area->bottom() - $y); ++$index) {
            $rowY = $y + $index - $scrollOffset;
            $isCursor = $index === $this->cursor && $this->drawsFocused($context);
            $isChecked = $this->isChecked($index);

            $mark = $isChecked ? OptionMarks::CHECKED : OptionMarks::UNCHECKED;

            $style = $theme->optionStyle($isCursor, $isChecked, $this->drawsFocused($context));

            $frame->putStyled($area->x, $rowY, $mark, $style);
            $frame->putStyled(
                $area->x + CharWidth::stringWidth($mark),
                $rowY,
                CharWidth::truncateToWidth($this->options[$index], max(0, $area->width - CharWidth::stringWidth($mark))),
                $style,
            );
        }
    }

    /**
     * Rows this widget draws above its options — its label and the spacer under it.
     */
    protected function rowOffset(): int
    {
        return $this->label !== '' ? 2 : 0;
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
        $optionIndex = $this->optionIndexAt($message, $area, $count);

        if ($optionIndex === null) {
            return new UpdateResult($this);
        }

        return new UpdateResult($this->cloneWith(cursor: $optionIndex)->toggleCurrent());
    }
}
