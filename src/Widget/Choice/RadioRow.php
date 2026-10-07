<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\PublishesHints;

/**
 * One choice out of several, side by side on a row: the horizontal Radio, as
 * CheckboxRow is the horizontal CheckboxGroup.
 */
final readonly class RadioRow implements OptionRow, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    public const SELECTED_MARK = OptionMarks::SELECTED;
    public const UNSELECTED_MARK = OptionMarks::UNSELECTED;

    /**
     * @param list<string> $options
     */
    public function __construct(
        private array $options = [],
        private int $selected = -1,
        private int $cursor = 0,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $clearable = false,
        private bool $wrapping = true,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        Options::assertDistinct($options, 'RadioRow');

        return new self(options: $options);
    }

    /**
     * For a choice that may also be none: pressing the option already picked puts
     * it back down rather than picking it again.
     *
     * Off by default, because a row that must answer should not be emptiable by a
     * stray keystroke. Nothing picked is a state this row can already hold and draw
     * — this is how a reader reaches it.
     */
    public function withClearable(bool $clearable = true): self
    {
        return $this->cloneWith(clearable: $clearable);
    }

    /**
     * A row comes round by default: it is short enough that walking off one end
     * onto the other is quicker than turning back. Say `wrapping(false)` for a row
     * whose ends should hold — a form field, where an arrow held down would
     * otherwise cycle the value forever.
     */
    public function withWrapping(bool $wrapping = true): self
    {
        return $this->cloneWith(wrapping: $wrapping);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::SELECT => ['enter', 'space'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_LEFT, Actions::CURSOR_RIGHT],
            'choose' => Actions::SELECT,
        ]);
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): static
    {
        Options::assertDistinct($options, 'RadioRow');

        if ($options === $this->options) {
            return $this;
        }

        return $this->cloneWith(
            options: $options,
            selected: Options::placeOf($this->selectedValue(), $options),
            cursor: Options::cursorAfter($this->options[$this->cursor] ?? null, $options),
        );
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): static
    {
        return $this->cloneWith(cursor: $cursor);
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    /**
     * A host holds what was chosen, not where it sat. The cursor goes there too:
     * the reader is looking at the option that is chosen.
     */
    public function withSelectedValue(?string $value): self
    {
        $place = Options::placeOf($value, $this->options);

        return $this->cloneWith(selected: $place, cursor: max(0, $place));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(OptionRowLayout::width($this->entries())),
            Constraint::min(1),
        );
    }

    public function measureHeight(int $width): int
    {
        return OptionRowLayout::height($this->entries(), $width);
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function selectedValue(): ?string
    {
        return $this->options[$this->selected] ?? null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg => $this->handleKey($message),
            $message instanceof MouseMsg && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height < 1) {
            return;
        }

        foreach (OptionRowLayout::placements($this->entries(), $area) as $index => [$x, $y, $width]) {
            if ($y >= $area->bottom()) {
                return;
            }

            $picked = $index === $this->selected;
            $focused = $this->drawsFocused($context);

            $frame->putStyled(
                $x,
                $y,
                ($picked ? self::SELECTED_MARK : self::UNSELECTED_MARK) . $this->options[$index],
                $context->theme->optionStyle($focused && $index === $this->cursor, $picked, $focused),
            );
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message): UpdateResult
    {
        if ($this->options === []) {
            return new UpdateResult($this);
        }

        $count = \count($this->options);
        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::CURSOR_LEFT) => new UpdateResult($this->cloneWith(cursor: $this->walked(-1, $count))),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => new UpdateResult($this->cloneWith(cursor: $this->walked(1, $count))),
            $bindings->matches($message, Actions::SELECT) => new UpdateResult($this->cloneWith(selected: $this->picking($this->cursor))),
            default => new UpdateResult($this),
        };
    }

    /**
     * Where an arrow leaves the cursor. It rests at either end, which is what a
     * row of two or three marks wants: circling back past the last one reads as a
     * jump rather than a step.
     */
    private function walked(int $step, int $count): int
    {
        return $this->wrapping
            ? ($this->cursor + $step + $count) % $count
            : max(0, min($count - 1, $this->cursor + $step));
    }

    /**
     * What a press lands on: the option it went to, or nothing at all when that is
     * the one already picked and this row may be cleared.
     */
    private function picking(int $index): int
    {
        return $this->clearable && $this->selected === $index ? -1 : $index;
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area): UpdateResult
    {
        $index = OptionRowLayout::indexAt($this->entries(), $area, $message->x, $message->y);

        if ($index === null) {
            return new UpdateResult($this);
        }

        return new UpdateResult($this->cloneWith(selected: $this->picking($index), cursor: $index));
    }

    /**
     * Every option as it is drawn. Both marks are the same width, so the
     * columns hold whichever one a redraw puts there.
     *
     * @return list<string>
     */
    private function entries(): array
    {
        return array_map(
            static fn (string $option): string => self::UNSELECTED_MARK . $option,
            $this->options,
        );
    }
}
