<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Cmd\ToastCmd;
use Elmphant\Tui\Core\Cmd\ToastPosition;
use Elmphant\Tui\Core\Cmd\ToastSeverity;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Text\TerminalText;
use Elmphant\Tui\Text\TextWrap;

/**
 * @internal
 */
final class Toasts
{
    private const MARGIN = 1;
    private const PADDING = 1;

    /**
     * @var list<array{ToastCmd, float}> each toast and the time it disappears
     */
    private array $showing = [];

    private bool $swallowsRelease = false;

    public function show(ToastCmd $toast, float $now): void
    {
        foreach ($this->showing as $place => [$shown]) {
            if ($toast->key !== null && $shown->key === $toast->key) {
                $this->showing[$place] = [$toast, $now + $toast->seconds];

                return;
            }
        }

        $this->showing[] = [$toast, $now + $toast->seconds];
    }

    /**
     * @return bool whether any toast disappeared
     */
    public function expire(float $now): bool
    {
        $before = count($this->showing);
        $this->showing = array_values(array_filter($this->showing, static fn (array $shown): bool => $shown[1] > $now));

        return count($this->showing) !== $before;
    }

    public function nextDeadline(): ?float
    {
        return $this->showing === [] ? null : min(array_column($this->showing, 1));
    }

    /**
     * A press on a toast closes it; the press and its release never reach the app.
     */
    public function takes(Message $message, Rect $screen): bool
    {
        if (!$message instanceof MouseMsg) {
            return false;
        }

        if ($message->action === MouseAction::Release && $this->swallowsRelease) {
            $this->swallowsRelease = false;

            return true;
        }

        if ($message->action !== MouseAction::Press) {
            return false;
        }

        foreach ($this->placed($screen) as [$place, $area]) {
            if ($area->contains($message->x, $message->y)) {
                array_splice($this->showing, $place, 1);
                $this->swallowsRelease = true;

                return true;
            }
        }

        return false;
    }

    public function drawOver(Frame $frame, RenderContext $context): void
    {
        $base = $context->theme->base;
        foreach ($this->placed(Rect::fromSize($frame->width, $frame->height)) as [$place, $area, $lines]) {
            $toast = $this->showing[$place][0];
            $accent = new Style(foreground: self::tokenOf($toast->severity)->resolve($context->theme)->foreground, background: $base->background);
            $frame->fillRect($area->x, $area->y, $area->width, $area->height, $base->foregroundValue(), $base->backgroundValue());
            Block::new()
                ->withBorder(BorderStyle::Rounded)
                ->withTitle(TerminalText::plain($toast->title))
                ->withBorderStyle($accent)
                ->withTitleStyle($accent->bold())
                ->render($frame, $area);
            foreach ($lines as $row => $line) {
                $frame->putStyled($area->x + 1 + self::PADDING, $area->y + 1 + $row, $line, $base);
            }
        }
    }

    /**
     * Newest first, each stacked away from its corner; a toast with no room is left out.
     *
     * @return list<array{int, Rect, list<string>}> the toast's place in $showing, its area and its lines
     */
    private function placed(Rect $screen): array
    {
        $placed = [];
        $stacked = [];
        for ($place = count($this->showing) - 1; $place >= 0; --$place) {
            $toast = $this->showing[$place][0];
            $width = min($toast->width, $screen->width - 2 * self::MARGIN);
            $inner = $width - 2 - 2 * self::PADDING;
            if ($inner < 1) {
                continue;
            }

            $lines = TextWrap::wrap(TerminalText::plain($toast->text), $inner);
            $height = count($lines) + 2;
            $below = $stacked[$toast->position->name] ?? 0;
            if (self::MARGIN + $below + $height > $screen->height) {
                continue;
            }
            $stacked[$toast->position->name] = $below + $height;

            $placed[] = [$place, new Rect(
                match ($toast->position) {
                    ToastPosition::TopLeft, ToastPosition::BottomLeft => self::MARGIN,
                    ToastPosition::TopCenter, ToastPosition::BottomCenter => intdiv($screen->width - $width, 2),
                    ToastPosition::TopRight, ToastPosition::BottomRight => $screen->width - self::MARGIN - $width,
                },
                match ($toast->position) {
                    ToastPosition::TopLeft, ToastPosition::TopCenter, ToastPosition::TopRight => self::MARGIN + $below,
                    default => $screen->height - self::MARGIN - $below - $height,
                },
                $width,
                $height,
            ), $lines];
        }

        return $placed;
    }

    private static function tokenOf(ToastSeverity $severity): StyleToken
    {
        return match ($severity) {
            ToastSeverity::Info => StyleToken::Info,
            ToastSeverity::Success => StyleToken::Success,
            ToastSeverity::Warning => StyleToken::Warning,
            ToastSeverity::Error => StyleToken::Error,
        };
    }
}
