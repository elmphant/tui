<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Reducer;
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
use LogicException;

final readonly class CheckboxRow implements Focusable, KeybindingsAware, MeasureHeight
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public const CHECKED_MARK = '[x] ';
    public const UNCHECKED_MARK = '[ ] ';

    /**
     * @param list<string> $options
     * @param list<bool>   $checked
     */
    public function __construct(
        private array $options = [],
        private array $checked = [],
        private int $cursor = 0,
        private bool $focused = false,
        private string $prefix = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        return new self(
            options: $options,
            checked: array_fill(0, count($options), false),
        );
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): self
    {
        return $this->cloneWith(options: $options);
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

    public function withPrefix(string $prefix): self
    {
        return $this->cloneWith(prefix: $prefix);
    }

    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor);
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
        return Constraint::min(1);
    }

    public function measureHeight(int $width): int
    {
        return OptionRowLayout::height($this->entries(), $width - CharWidth::stringWidth($this->prefix));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg && $this->focused => $this->handleKey($message),
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
            $isCursor = $this->focused && $index === $this->cursor;

            $frame->putStyled(
                $x,
                $y,
                ($isChecked ? self::CHECKED_MARK : self::UNCHECKED_MARK) . $this->options[$index],
                $theme->optionStyle($isCursor, $isChecked, $this->focused),
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
            $bindings->matches($message, Actions::CURSOR_LEFT) => new UpdateResult($this->cloneWith(cursor: ($this->cursor - 1 + $count) % $count)),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => new UpdateResult($this->cloneWith(cursor: ($this->cursor + 1) % $count)),
            $bindings->matches($message, Actions::TOGGLE) => $this->toggleAt($this->cursor),
            default => new UpdateResult($this),
        };
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
