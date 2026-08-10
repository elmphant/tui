<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;

final readonly class Button implements Focusable, KeybindingsAware, MeasureWidth
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private function __construct(
        private string $label,
        private Message $message,
        private ButtonTone $tone = ButtonTone::Default,
        private bool $focused = false,
        private bool $disabled = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function new(string $label, Message $message): self
    {
        return new self(label: $label, message: $message);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::SELECT => ['enter', 'space'],
        ]);
    }

    public function withTone(ButtonTone $tone): self
    {
        return $this->cloneWith(tone: $tone);
    }

    public function withDisabled(bool $disabled = true): self
    {
        return $this->cloneWith(disabled: $disabled);
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
        return Constraint::exact(3);
    }

    public function measureWidth(): int
    {
        return CharWidth::stringWidth($this->label) + 4;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($this->disabled) {
            return new UpdateResult($this);
        }

        return match (true) {
            $message instanceof KeyMsg
                && $this->focused
                && $this->effectiveKeybindings()->matches($message, Actions::SELECT) => new UpdateResult($this, Cmd::message($this->message)),
            $message instanceof MouseMsg
                && $message->isClick()
                && $message->inRect($area) => new UpdateResult($this, Cmd::message($this->message)),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 4 || $area->height < 3) {
            return;
        }

        $theme = $context->theme;

        $toneStyle = match (true) {
            $this->disabled => $theme->dim,
            $this->tone === ButtonTone::Primary => $theme->accent,
            $this->tone === ButtonTone::Danger => $theme->error,
            default => $theme->borderStyle,
        };
        $showsFocus = $this->focused && !$this->disabled;

        $borderStyle = match (true) {
            !$showsFocus => $toneStyle,
            $this->tone === ButtonTone::Default => $theme->focusedBorderStyle,
            default => $toneStyle->bold(),
        };

        $block = Block::new()
            ->border($showsFocus ? $theme->focusedBorder : $theme->border)
            ->borderStyle($borderStyle)
            ->padding(Spacing::horizontal(1));
        $block->render($frame, $area);
        $inner = $block->inner($area);

        $labelStyle = $this->disabled ? $theme->dim : $theme->base;

        if ($showsFocus && $this->tone !== ButtonTone::Default) {
            $fill = $toneStyle->foreground ?? Color::none();
            $labelStyle = new Style(foreground: $fill->contrastingText(), background: $fill);
            $frame->fillRect(
                $inner->x,
                $inner->y,
                $inner->width,
                $inner->height,
                $labelStyle->foregroundValue(),
                $labelStyle->backgroundValue(),
                $labelStyle->attributes,
            );
        }

        $label = CharWidth::truncateToWidth($this->label, $inner->width);

        $frame->putStyled(
            $inner->x + intdiv($inner->width - CharWidth::stringWidth($label), 2),
            $inner->y,
            $label,
            $labelStyle,
        );
    }
}
