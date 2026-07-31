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

final readonly class CheckboxRow implements Focusable, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    /**
     * @param list<string> $labels
     * @param list<bool>   $checked
     */
    public function __construct(
        private array $labels = [],
        private array $checked = [],
        private int $cursor = 0,
        private bool $focused = false,
        private string $prefix = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @param list<string> $labels
     */
    public static function new(array $labels): self
    {
        return new self(
            labels: $labels,
            checked: array_fill(0, count($labels), false),
        );
    }

    /**
     * @param list<string> $labels
     */
    public function withLabels(array $labels): self
    {
        return $this->cloneWith(labels: $labels);
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
        return Constraint::exact(1);
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
        $x = $area->x;
        $y = $area->y;

        if ($this->prefix !== '') {
            $x += $frame->putStyled($x, $y, $this->prefix, $theme->dim);
        }

        foreach ($this->labels as $index => $label) {
            $isChecked = $this->checked[$index] ?? false;
            $isCursor = $this->focused && $index === $this->cursor;

            $mark = $isChecked ? '[x] ' : '[ ] ';

            $style = $theme->optionStyle($isCursor, $isChecked, $this->focused);

            $x += $frame->putStyled($x, $y, $mark, $style);
            $x += $frame->putStyled($x, $y, $label, $style);
            $x += $frame->putStyled($x, $y, '  ', $theme->dim);
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message): UpdateResult
    {
        if ($this->labels === []) {
            return new UpdateResult($this);
        }

        $count = count($this->labels);
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
        $positions = $this->optionPositions($area->x);

        foreach ($positions as $index => [$startX, $endX]) {
            if ($message->x >= $startX && $message->x < $endX) {
                $toggled = $this->toggleAt($index);
                $model = self::requireModel($toggled->model);

                return new UpdateResult($model->withCursor($index));
            }
        }

        return new UpdateResult($this);
    }

    private static function requireModel(Reducer $model): self
    {
        if (!$model instanceof self) {
            throw new LogicException(sprintf('CheckboxRow update must return %s, got %s', self::class, get_debug_type($model)));
        }

        return $model;
    }

    /**
     * @return list<array{int, int}> [startX, endX] for each option
     */
    private function optionPositions(int $baseX): array
    {
        $x = $baseX;
        if ($this->prefix !== '') {
            $x += CharWidth::stringWidth($this->prefix);
        }

        $positions = [];
        foreach ($this->labels as $label) {
            $startX = $x;
            $width = 4 + CharWidth::stringWidth($label) + 2;
            $positions[] = [$startX, $startX + $width];
            $x += $width;
        }

        return $positions;
    }
}
