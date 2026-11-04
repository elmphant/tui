<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;

final readonly class RadioRow implements HoldsAValue, OptionRow, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    public const SELECTED_MARK = OptionMarks::SELECTED;
    public const UNSELECTED_MARK = OptionMarks::UNSELECTED;

    /**
     * @param list<string> $options
     */
    private function __construct(
        private array $options = [],
        private ?int $selected = null,
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
     * Allow selecting the current option to clear it; disabled by default to prevent accidental empty choices.
     */
    public function withClearable(bool $clearable = true): self
    {
        return $this->cloneWith(clearable: $clearable);
    }

    /**
     * Wrap by default; use wrapping(false) to stop at either end, such as in a form field.
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
            cursor: Options::cursorAfter($this->options[$this->cursor] ?? null, $options, $this->cursor),
        );
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): static
    {
        return $this->cloneWith(cursor: Options::cursorWithin($cursor, $this->options));
    }

    public function withSelectedIndex(?int $selected): self
    {
        return $this->cloneWith(selected: $selected !== null && isset($this->options[$selected]) ? $selected : null);
    }

    /**
     * Move the cursor to the selected value too.
     */
    public function withSelectedValue(?string $value): self
    {
        $place = Options::placeOf($value, $this->options);

        return $this->cloneWith(selected: $place, cursor: $place ?? 0);
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

    public function selectedIndex(): ?int
    {
        return $this->selected;
    }

    public function selectedValue(): ?string
    {
        return $this->selected === null ? null : $this->options[$this->selected] ?? null;
    }

    public function value(): ?string
    {
        return $this->selectedValue();
    }

    public function withValue(string|bool|array|null $value): static
    {
        return \is_string($value) || $value === null
            ? $this->withSelectedValue($value)
            : throw ValueItCannotHold::given($this, $value);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg => $this->handleKey($message),
            $message instanceof MouseMsg && $message->isClick() && $message->isIn($area) => $this->handleClick($message, $area),
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
                OptionStyle::of($context->theme, $focused && $index === $this->cursor, $picked, $focused),
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

    private function walked(int $step, int $count): int
    {
        return $this->wrapping
            ? ($this->cursor + $step + $count) % $count
            : max(0, min($count - 1, $this->cursor + $step));
    }

    private function picking(int $index): ?int
    {
        return $this->clearable && $this->selected === $index ? null : $index;
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
     * Keep selected and unselected marks the same width so option columns stay aligned.
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
