<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class Button implements Focusable, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private function __construct(
        private string $label,
        private Message $message,
        private ButtonTone $tone = ButtonTone::Default,
        private bool $disabled = false,
        private bool $armed = false,
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

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(['press' => Actions::SELECT]);
    }

    public function withTone(ButtonTone $tone): self
    {
        return $this->cloneWith(tone: $tone);
    }

    public function withDisabled(bool $disabled = true): self
    {
        return $this->cloneWith(disabled: $disabled);
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(CharWidth::stringWidth($this->label) + 4),
            Constraint::exact(3),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        // Clear the pressed state when disabled, since the release may never arrive.
        if ($this->disabled) {
            return new UpdateResult($this->withArmed(false));
        }

        return match (true) {
            $message instanceof KeyMsg
                && $this->effectiveKeybindings()->matches($message, Actions::SELECT) => new UpdateResult($this, $this->press()),
            $message instanceof MouseMsg
                && $message->action === MouseAction::Press => new UpdateResult($this->withArmed($message->isIn($area))),
            $message instanceof MouseMsg
                && $message->action === MouseAction::Release => $this->armed && $message->isIn($area)
                    ? new UpdateResult($this->withArmed(false), Cmd::message($this->message))
                    : new UpdateResult($this->withArmed(false)),
            default => new UpdateResult($this),
        };
    }

    public function press(): ?Cmd
    {
        return $this->disabled ? null : Cmd::message($this->message);
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
        $showsFocus = ($this->drawsFocused($context) || $this->armed) && !$this->disabled;

        $borderStyle = match (true) {
            !$showsFocus => $toneStyle,
            $this->tone === ButtonTone::Default => $theme->focusedBorderStyle,
            default => $toneStyle->bold(),
        };

        if ($this->armed && !$this->disabled) {
            $borderStyle = $borderStyle->bold();
        }

        // Use a heavier border for buttons even though Unicode has no matching heavy rounded corners.
        $block = Block::new()
            ->withBorderType($showsFocus ? BorderType::Thick : $theme->borderType)
            ->withBorderStyle($borderStyle)
            ->withPadding(Spacing::horizontal(1));
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

    private function withArmed(bool $armed): self
    {
        return $this->cloneWith(armed: $armed);
    }
}
