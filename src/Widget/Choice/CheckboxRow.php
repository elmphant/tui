<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Reducer;
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
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\PublishesHints;
use LogicException;

final readonly class CheckboxRow implements OptionRow, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    public const CHECKED_MARK = OptionMarks::CHECKED;
    public const UNCHECKED_MARK = OptionMarks::UNCHECKED;

    /**
     * @param list<string> $options
     * @param list<bool>   $checked
     */
    public function __construct(
        private array $options = [],
        private array $checked = [],
        private int $cursor = 0,
        private string $prefix = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $wrapping = true,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        Options::assertDistinct($options, 'CheckboxRow');

        return new self(
            options: $options,
            checked: array_fill(0, count($options), false),
        );
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
        Options::assertDistinct($options, 'CheckboxRow');

        if ($options === $this->options) {
            return $this;
        }

        $ticked = [];
        foreach ($this->checkedIndices() as $index) {
            $place = Options::placeOf($this->options[$index] ?? null, $options);

            if ($place >= 0) {
                $ticked[$place] = true;
            }
        }

        $checked = [];
        foreach (array_keys($options) as $place) {
            $checked[] = isset($ticked[$place]);
        }

        return $this->cloneWith(
            options: $options,
            checked: $checked,
            cursor: Options::cursorAfter($this->options[$this->cursor] ?? null, $options),
        );
    }

    /**
     * @param list<bool> $checked
     */
    public function withChecked(array $checked): self
    {
        return $this->cloneWith(checked: $checked);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::TOGGLE => ['enter', 'space'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_LEFT, Actions::CURSOR_RIGHT],
            'toggle' => Actions::TOGGLE,
        ]);
    }

    public function withPrefix(string $prefix): self
    {
        return $this->cloneWith(prefix: $prefix);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): static
    {
        return $this->cloneWith(cursor: $cursor);
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

    public function isChecked(int $index): bool
    {
        return $this->checked[$index] ?? false;
    }

    /**
     * @return list<int>
     */
    public function checkedIndices(): array
    {
        return array_keys(array_filter($this->checked));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(
                CharWidth::stringWidth($this->prefix) + OptionRowLayout::width($this->entries()),
            ),
            Constraint::min(1),
        );
    }

    public function measureHeight(int $width): int
    {
        return OptionRowLayout::height($this->entries(), $width - CharWidth::stringWidth($this->prefix));
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

        $theme = $context->theme;

        if ($this->prefix !== '') {
            $frame->putStyled($area->x, $area->y, $this->prefix, $theme->dim);
        }

        foreach (OptionRowLayout::placements($this->entries(), $this->optionsArea($area)) as $index => [$x, $y, $width]) {
            if ($y >= $area->bottom()) {
                return;
            }

            $isChecked = $this->checked[$index] ?? false;
            $focused = $this->drawsFocused($context);

            $frame->putStyled(
                $x,
                $y,
                ($isChecked ? self::CHECKED_MARK : self::UNCHECKED_MARK) . $this->options[$index],
                $theme->optionStyle($focused && $index === $this->cursor, $isChecked, $focused),
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

        $count = count($this->options);
        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::CURSOR_LEFT) => new UpdateResult($this->cloneWith(cursor: $this->walked(-1, $count))),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => new UpdateResult($this->cloneWith(cursor: $this->walked(1, $count))),
            $bindings->matches($message, Actions::TOGGLE) => $this->toggleAt($this->cursor),
            default => new UpdateResult($this),
        };
    }

    /**
     * Where an arrow leaves the cursor. It rests at either end, which is what a
     * row told not to wrap asked for.
     */
    private function walked(int $step, int $count): int
    {
        return $this->wrapping
            ? ($this->cursor + $step + $count) % $count
            : max(0, min($count - 1, $this->cursor + $step));
    }

    /**
     * @return UpdateResult<static>
     */
    private function toggleAt(int $index): UpdateResult
    {
        if (!isset($this->checked[$index])) {
            return new UpdateResult($this);
        }

        $checked = $this->checked;
        $checked[$index] = !$checked[$index];

        return new UpdateResult($this->cloneWith(checked: $checked));
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area): UpdateResult
    {
        $index = OptionRowLayout::indexAt($this->entries(), $this->optionsArea($area), $message->x, $message->y);

        if ($index === null) {
            return new UpdateResult($this);
        }

        $model = self::requireModel($this->toggleAt($index)->model);

        return new UpdateResult($model->withCursor($index));
    }

    private static function requireModel(Reducer $model): self
    {
        if (!$model instanceof self) {
            throw new LogicException(sprintf('CheckboxRow update must return %s, got %s', self::class, get_debug_type($model)));
        }

        return $model;
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
            static fn (string $label): string => self::UNCHECKED_MARK . $label,
            $this->options,
        );
    }

    /**
     * The prefix keeps the columns it was drawn in, so the options start after it.
     */
    private function optionsArea(Rect $area): Rect
    {
        if ($this->prefix === '') {
            return $area;
        }

        $indent = CharWidth::stringWidth($this->prefix);

        return new Rect($area->x + $indent, $area->y, max(0, $area->width - $indent), $area->height);
    }
}
