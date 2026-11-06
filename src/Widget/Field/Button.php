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
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focus\CapturesThePointer;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class Button implements Focusable, KeybindingsAware, PublishesHints, Committing, CapturesThePointer
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const BEVEL_DEPTH = 0.3;

    private function __construct(
        private string $label,
        private ?Message $message,
        private ButtonTone $tone = ButtonTone::Default,
        private bool $disabled = false,
        private bool $armed = false,
        private bool $bordered = true,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * A bound button answers `Pressed`. The message, when given, is sent as well, for a host that hears no outcome.
     */
    public static function new(string $label, ?Message $message = null): self
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

    /**
     * Without a border the button is one row: `[ Save ]`. It draws that way too wherever it gets fewer than three rows.
     */
    public function withBorder(bool $bordered = true): self
    {
        return $this->cloneWith(bordered: $bordered);
    }

    public function withDisabled(bool $disabled = true): self
    {
        return $this->cloneWith(disabled: $disabled);
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    public function capturesThePointer(): bool
    {
        return $this->armed;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(CharWidth::stringWidth($this->label) + 4),
            Constraint::exact($this->bordered ? 3 : 1),
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
                    ? new UpdateResult($this->withArmed(false), $this->press())
                    : new UpdateResult($this->withArmed(false)),
            default => new UpdateResult($this),
        };
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        $pressed = !$this->disabled && match (true) {
            $message instanceof KeyMsg => $this->effectiveKeybindings()->matches($message, Actions::SELECT),
            $message instanceof MouseMsg => $message->action === MouseAction::Release && $this->armed && $message->isIn($area),
            default => false,
        };

        return $pressed ? new Pressed($message) : null;
    }

    public function press(): ?Cmd
    {
        return $this->disabled || $this->message === null ? null : Cmd::message($this->message);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 4 || $area->height < 1) {
            return;
        }

        $theme = $context->theme;
        $toneStyle = $this->toneStyle($theme);
        $showsFocus = ($this->drawsFocused($context) || $this->armed) && !$this->disabled;

        if (!$this->bordered || $area->height < 3) {
            $this->renderOnOneRow($frame, $area, $theme, $toneStyle, $showsFocus);

            return;
        }

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
        $inner = $block->inner($area);

        if ($theme->raisedButtons) {
            [$lit, $shaded] = $context->colorProfile->blendsSmoothly() ? self::bevelOf($borderStyle) : [$borderStyle, $borderStyle];
            self::drawRaised($frame, $area, $lit, $shaded, sunk: $this->armed && !$this->disabled);
        } else {
            $block->render($frame, $area);
        }

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

    /**
     * @return array{Style, Style}
     */
    private static function bevelOf(Style $style): array
    {
        $colour = $style->foreground ?? Color::none();

        return [
            $style->withForeground($colour->blend(Color::rgb(255, 255, 255), self::BEVEL_DEPTH)),
            $style->withForeground($colour->blend(Color::rgb(0, 0, 0), self::BEVEL_DEPTH)),
        ];
    }

    private static function drawRaised(Frame $frame, Rect $area, Style $lit, Style $shaded, bool $sunk): void
    {
        [$topLeft, $top, $left, $topRight, $right, $bottomLeft, $bottom, $bottomRight] = $sunk
            ? ['┏', '━', '┃', '┑', '│', '┖', '─', '┘']
            : ['┌', '─', '│', '┒', '┃', '┕', '━', '┛'];
        [$topLeftStyle, $bottomRightStyle] = $sunk ? [$shaded, $lit] : [$lit, $shaded];
        $rightColumn = $area->right() - 1;
        $bottomRow = $area->bottom() - 1;
        $width = $area->width - 2;
        $height = $area->height - 2;

        $frame->putStyled($area->x, $area->y, $topLeft, $topLeftStyle);
        $frame->fillStyled(new Rect($area->x + 1, $area->y, $width, 1), $topLeftStyle, $top);
        $frame->fillStyled(new Rect($area->x, $area->y + 1, 1, $height), $topLeftStyle, $left);
        $frame->putStyled($rightColumn, $area->y, $topRight, $shaded);
        $frame->fillStyled(new Rect($rightColumn, $area->y + 1, 1, $height), $bottomRightStyle, $right);
        $frame->putStyled($area->x, $bottomRow, $bottomLeft, $shaded);
        $frame->fillStyled(new Rect($area->x + 1, $bottomRow, $width, 1), $bottomRightStyle, $bottom);
        $frame->putStyled($rightColumn, $bottomRow, $bottomRight, $bottomRightStyle);
    }

    private function toneStyle(Theme $theme): Style
    {
        return match (true) {
            $this->disabled => $theme->dim,
            $this->tone === ButtonTone::Primary => $theme->accent,
            $this->tone === ButtonTone::Danger => $theme->error,
            default => $theme->borderStyle,
        };
    }

    private function renderOnOneRow(Frame $frame, Rect $area, Theme $theme, Style $toneStyle, bool $showsFocus): void
    {
        $label = ' ' . CharWidth::truncateToWidth($this->label, $area->width - 4) . ' ';
        $width = CharWidth::stringWidth($label) + 2;
        $x = $area->x + intdiv($area->width - $width, 2);
        $y = $area->y + intdiv($area->height - 1, 2);

        $labelStyle = match (true) {
            $this->disabled => $theme->dim,
            !$showsFocus => $theme->base,
            $this->tone === ButtonTone::Default => $theme->cursorStyle,
            default => new Style(foreground: ($toneStyle->foreground ?? Color::none())->contrastingText(), background: $toneStyle->foreground),
        };
        $bracketStyle = $showsFocus ? $toneStyle->bold() : $toneStyle;

        $frame->putStyled($x, $y, '[', $bracketStyle);
        $frame->putStyled($x + 1, $y, $label, $this->armed ? $labelStyle->bold() : $labelStyle);
        $frame->putStyled($x + $width - 1, $y, ']', $bracketStyle);
    }

    private function withArmed(bool $armed): self
    {
        return $this->cloneWith(armed: $armed);
    }
}
