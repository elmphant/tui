<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
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
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * One choice out of several, side by side on a row: the horizontal Radio, as
 * CheckboxRow is the horizontal CheckboxGroup.
 */
final readonly class RadioRow implements Focusable, KeybindingsAware, MeasureHeight
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public const PICKED_MARK = '● ';
    public const FREE_MARK = '○ ';

    /**
     * @param list<string> $options
     */
    public function __construct(
        private array $options = [],
        private int $selected = -1,
        private int $cursor = 0,
        private bool $focused = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        return new self(options: $options);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::SELECT => ['enter', 'space'],
        ]);
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
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
        return OptionRowLayout::height($this->entries(), $width);
    }

    public function selected(): int
    {
        return $this->selected;
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

        foreach (OptionRowLayout::placements($this->entries(), $area) as $index => [$x, $y, $width]) {
            if ($y >= $area->bottom()) {
                return;
            }

            $picked = $index === $this->selected;

            $frame->putStyled(
                $x,
                $y,
                ($picked ? self::PICKED_MARK : self::FREE_MARK) . $this->options[$index],
                $context->theme->optionStyle($this->focused && $index === $this->cursor, $picked, $this->focused),
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
            $bindings->matches($message, Actions::CURSOR_LEFT) => new UpdateResult($this->cloneWith(cursor: ($this->cursor - 1 + $count) % $count)),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => new UpdateResult($this->cloneWith(cursor: ($this->cursor + 1) % $count)),
            $bindings->matches($message, Actions::SELECT) => new UpdateResult($this->cloneWith(selected: $this->cursor)),
            default => new UpdateResult($this),
        };
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

        return new UpdateResult($this->cloneWith(selected: $index, cursor: $index));
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
            static fn (string $option): string => self::FREE_MARK . $option,
            $this->options,
        );
    }
}
