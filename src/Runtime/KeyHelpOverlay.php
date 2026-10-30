<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Text\CharWidth;

/**
 * @internal the key help the runtime lays over the screen. Open or closed is the
 * runtime's to know, as an open chord is: it changes no model.
 */
final class KeyHelpOverlay
{
    private const TITLE = 'Keys · Esc to close';
    private const GAP = 2;
    private const PAGE = 10;

    private bool $open = false;
    private int $scroll = 0;
    private int $lastScroll = 0;

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * While open, consume all keys and pointer events so widgets behind the help cannot react.
     */
    public function takes(object $message, object $model, FocusState $focus): bool
    {
        if (!$model instanceof DescribesKeys || (!$message instanceof KeyMsg && !$message instanceof MouseMsg)) {
            return false;
        }

        if (!$this->open) {
            if ($message instanceof KeyMsg && $model->opensKeyHelp($message, $focus)) {
                $this->open = true;
                $this->scroll = 0;

                return true;
            }

            return false;
        }

        if ($message instanceof MouseMsg) {
            $this->scrollBy(match ($message->action) {
                MouseAction::WheelUp => -1,
                MouseAction::WheelDown => 1,
                default => 0,
            });

            return true;
        }

        if ($message->key === Key::Escape || $message->key === '?' || $model->opensKeyHelp($message, $focus)) {
            $this->open = false;

            return true;
        }

        // A shortcut such as Ctrl+Q must still work: it closes the help and acts.
        if ($message->ctrl || $message->alt) {
            $this->open = false;

            return false;
        }

        $this->scrollBy(match (true) {
            $message->key === Key::Up, $message->key === 'k' => -1,
            $message->key === Key::Down, $message->key === 'j' => 1,
            $message->key === Key::PageUp => -self::PAGE,
            $message->key === Key::PageDown, $message->key === ' ' => self::PAGE,
            $message->key === Key::Home => -\PHP_INT_MAX >> 1,
            $message->key === Key::End => \PHP_INT_MAX >> 1,
            default => 0,
        });

        return true;
    }

    public function drawOver(Frame $frame, RenderContext $context, object $model, FocusState $focus): void
    {
        if (!$this->open || !$model instanceof DescribesKeys) {
            return;
        }

        $lines = self::lines($model->keyHelp($focus), $context);
        $content = 0;
        foreach ($lines as [$text]) {
            $content = max($content, CharWidth::stringWidth($text));
        }

        $width = min($frame->width - 2, max($content, CharWidth::stringWidth(self::TITLE)) + 4);
        $height = min($frame->height - 2, \count($lines) + 2);
        if ($width < 3 || $height < 3) {
            return;
        }

        $area = new Rect(intdiv($frame->width - $width, 2), intdiv($frame->height - $height, 2), $width, $height);
        $base = $context->theme->base;
        $frame->fillRect($area->x, $area->y, $area->width, $area->height, $base->foregroundValue(), $base->backgroundValue());
        Block::new()
            ->withBorder(BorderStyle::Rounded)
            ->withTitle(self::TITLE)
            ->withBorderStyle($context->theme->focusedBorderStyle)
            ->withTitleStyle($context->theme->titleStyle)
            ->render($frame, $area);

        $rows = $height - 2;
        $this->scroll = max(0, min($this->scroll, \count($lines) - $rows));
        $this->lastScroll = $this->scroll;

        foreach (\array_slice($lines, $this->scroll, $rows) as $row => [$text, $style]) {
            $frame->putStyled($area->x + 2, $area->y + 1 + $row, CharWidth::truncateToWidth($text, $width - 4), $style);
        }

        $frame->cursorPosition = null;
    }

    private function scrollBy(int $rows): void
    {
        $this->scroll = max(0, $this->lastScroll + $rows);
        $this->lastScroll = $this->scroll;
    }

    /**
     * A label such as `1` arrives as an integer key, the way PHP keeps numeric strings.
     *
     * @param array<array-key, array<array-key, string>> $groups
     *
     * @return list<array{string, Style}>
     */
    private static function lines(array $groups, RenderContext $context): array
    {
        $labelWidth = 0;
        foreach ($groups as $keys) {
            foreach (array_keys($keys) as $label) {
                $labelWidth = max($labelWidth, CharWidth::stringWidth((string)$label));
            }
        }

        $lines = [];
        foreach ($groups as $title => $keys) {
            if ($keys === []) {
                continue;
            }

            if ($lines !== []) {
                $lines[] = ['', $context->theme->base];
            }

            $lines[] = [(string)$title, $context->theme->accent->bold()];
            foreach ($keys as $label => $description) {
                $label = (string)$label;
                $lines[] = [$label . str_repeat(' ', $labelWidth - CharWidth::stringWidth($label) + self::GAP) . $description, $context->theme->base];
            }
        }

        return $lines;
    }
}
