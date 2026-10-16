<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class Checkbox implements Focusable, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    public const CHECKED_MARK = OptionMarks::CHECKED;
    public const UNCHECKED_MARK = OptionMarks::UNCHECKED;

    public function __construct(
        private string $label = '',
        private bool $checked = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function new(string $label): self
    {
        return new self(label: $label);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::TOGGLE => ['enter', 'space'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(['toggle' => Actions::TOGGLE]);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function withChecked(bool $checked): self
    {
        return $this->cloneWith(checked: $checked);
    }

    public function sizing(): Sizing
    {
        $mark = $this->checked ? self::CHECKED_MARK : self::UNCHECKED_MARK;

        return Sizing::of(
            Constraint::flex()->withBasis(CharWidth::stringWidth($mark) + CharWidth::stringWidth($this->label)),
            Constraint::exact(1),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg
                && $this->effectiveKeybindings()->matches($message, Actions::TOGGLE) => new UpdateResult($this->cloneWith(checked: !$this->checked)),
            $message instanceof MouseMsg
                && $message->isClick()
                && $message->inRect($area) => new UpdateResult($this->cloneWith(checked: !$this->checked)),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height < 1) {
            return;
        }

        $theme = $context->theme;
        $mark = $this->checked ? self::CHECKED_MARK : self::UNCHECKED_MARK;

        $style = match (true) {
            $this->drawsFocused($context) => $theme->cursorStyle,
            $this->checked => $theme->accent,
            default => $theme->dim,
        };

        $column = $area->x;
        $column += $frame->putStyled($column, $area->y, CharWidth::truncateToWidth($mark, $area->width), $style);
        $remainingWidth = max(0, $area->width - CharWidth::stringWidth($mark));
        $frame->putStyled($column, $area->y, CharWidth::truncateToWidth($this->label, $remainingWidth), $style);
    }
}
