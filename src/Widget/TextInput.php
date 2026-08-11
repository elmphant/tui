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
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Grapheme;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;

final readonly class TextInput implements Focusable, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public function __construct(
        private string $value = '',
        private int $cursor = 0,
        private int $viewportOffset = 0,
        private bool $focused = false,
        private string $placeholder = '',
        private int $charLimit = 0,
        private EchoMode $echoMode = EchoMode::Normal,
        private InputDecoration $decoration = InputDecoration::Underline,
        private CursorShape $cursorShape = CursorShape::BlinkingBlock,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::LINE_START => ['home'],
            Actions::LINE_END => ['end'],
            Actions::DELETE_CHAR_BACKWARD => ['backspace'],
            Actions::DELETE_CHAR_FORWARD => ['delete'],
            Actions::DELETE_WORD_BACKWARD => ['ctrl+backspace'],
            Actions::CLEAR_LINE => ['ctrl+u'],
            Actions::DELETE_WORD_FORWARD => ['ctrl+delete'],
        ]);
    }

    public static function new(string $placeholder = ''): self
    {
        return new self(placeholder: $placeholder);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor);
    }

    /**
     * @internal viewport state is normally derived from cursor movement
     */
    public function withViewportOffset(int $viewportOffset): self
    {
        return $this->cloneWith(viewportOffset: $viewportOffset);
    }

    public function echoMode(): EchoMode
    {
        return $this->echoMode;
    }

    public function withValue(string $value): self
    {
        return $this->cloneWith(value: $value, cursor: self::graphemeLength($value));
    }

    public function withCharLimit(int $limit): self
    {
        return $this->cloneWith(charLimit: $limit);
    }

    public function withEchoMode(EchoMode $echoMode): self
    {
        return $this->cloneWith(echoMode: $echoMode);
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(placeholder: $placeholder);
    }

    /**
     * Choose the caret: a block by default, since a bar is easy to miss.
     */
    public function withCursorShape(CursorShape $shape): self
    {
        return $this->cloneWith(cursorShape: $shape);
    }

    /**
     * Choose how the field shows its bounds: underlined, filled, or unmarked.
     */
    public function withDecoration(InputDecoration $decoration): self
    {
        return $this->cloneWith(decoration: $decoration);
    }

    /**
     * Paint the theme's input background across the field instead of underlining it.
     */
    public function withStyled(bool $styled = true): self
    {
        return $this->cloneWith(decoration: $styled ? InputDecoration::Filled : InputDecoration::None);
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
            $message instanceof KeyMsg && $this->focused => $this->handleKey($message, $area),
            $message instanceof MouseMsg && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height < 1) {
            return;
        }

        $displayText = $this->echoMode->mask($this->value);
        $textLength = self::graphemeLength($displayText);
        $availableWidth = $area->width;

        $viewportOffset = $this->computeViewportOffset($availableWidth);

        $showPlaceholder = $textLength === 0 && $this->placeholder !== '';

        // Terminals colour the underline from the text, so the whole field —
        // empty tail included — has to carry one style.
        $fieldStyle = $this->fieldStyle($context, $showPlaceholder);
        $fieldStyle->apply($frame, new Rect($area->x, $area->y, $availableWidth, 1));

        $content = $showPlaceholder
            ? CharWidth::truncateToWidth($this->placeholder, $availableWidth)
            : self::substringByDisplayWidth($displayText, $viewportOffset, $availableWidth);

        $frame->putStyled($area->x, $area->y, $content, $fieldStyle);

        if ($this->focused) {
            $cursorDisplayX = CharWidth::stringWidth(self::graphemeSubstring($displayText, 0, $this->cursor));
            $cursorScreenX = $area->x + $cursorDisplayX - $viewportOffset;
            if ($cursorScreenX >= $area->x && $cursorScreenX < $area->right()) {
                $frame->setCursorPosition($cursorScreenX, $area->y, $this->cursorShape);
            }
        }
    }

    /**
     * Placeholder text is muted by colour, never by a partial attribute, so the
     * bounds marker stays uniform across the field.
     */
    private function fieldStyle(RenderContext $context, bool $showPlaceholder): Style
    {
        $theme = $context->theme;
        $tone = $showPlaceholder || !$this->focused ? $theme->dim : $theme->base;

        return match ($this->decoration) {
            InputDecoration::Underline => $tone->underline(),
            InputDecoration::Filled => $theme->inputStyle->background !== null
                ? $tone->background($theme->inputStyle->background)
                : $tone,
            InputDecoration::None => $tone,
        };
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message, Rect $area): UpdateResult
    {
        $bindings = $this->effectiveKeybindings();

        $updated = match (true) {
            $bindings->matches($message, Actions::CURSOR_LEFT) => $this->moveCursorLeft(),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => $this->moveCursorRight(),
            $bindings->matches($message, Actions::LINE_START) => $this->cursor > 0 ? $this->cloneWith(cursor: 0) : null,
            $bindings->matches($message, Actions::LINE_END) => $this->cursor < self::graphemeLength($this->value) ? $this->cloneWith(cursor: self::graphemeLength($this->value)) : null,
            $bindings->matches($message, Actions::CLEAR_LINE) => $this->value === '' ? null : $this->cloneWith(value: '', cursor: 0),
            $bindings->matches($message, Actions::DELETE_WORD_BACKWARD) => $this->deleteWordBackward(),
            $bindings->matches($message, Actions::DELETE_CHAR_BACKWARD) => $this->deleteCharBackward(),
            $bindings->matches($message, Actions::DELETE_WORD_FORWARD) => $this->deleteWordForward(),
            $bindings->matches($message, Actions::DELETE_CHAR_FORWARD) => $this->deleteCharForward(),
            is_string($message->key) && !$message->ctrl && !$message->alt && self::graphemeLength($message->key) === 1 => $this->insertCharacter($message->key),
            default => null,
        };

        if ($updated !== null) {
            $viewportOffset = $updated->computeViewportOffset($area->width);
            if ($viewportOffset !== $updated->viewportOffset) {
                $updated = $updated->cloneWith(viewportOffset: $viewportOffset);
            }

            return new UpdateResult($updated);
        }

        return new UpdateResult($this);
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area): UpdateResult
    {
        $viewportOffset = $this->computeViewportOffset($area->width);
        $clickDisplayX = $message->x - $area->x + $viewportOffset;
        $displayText = $this->echoMode->mask($this->value);
        $chars = Grapheme::split($displayText);
        $displayWidth = 0;
        $newCursor = self::graphemeLength($displayText);

        foreach ($chars as $index => $char) {
            $charWidth = CharWidth::graphemeWidth($char);
            if ($displayWidth + $charWidth > $clickDisplayX) {
                $newCursor = $index;
                break;
            }
            $displayWidth += $charWidth;
        }

        return new UpdateResult($this->cloneWith(cursor: $newCursor));
    }

    private static function substringByDisplayWidth(string $text, int $offsetColumns, int $maxColumns): string
    {
        $chars = Grapheme::split($text);
        $skipped = 0;
        $collected = '';
        $collectedWidth = 0;

        foreach ($chars as $char) {
            $charWidth = CharWidth::graphemeWidth($char);
            if ($skipped < $offsetColumns) {
                $skipped += $charWidth;
                continue;
            }
            if ($collectedWidth + $charWidth > $maxColumns) {
                break;
            }
            $collected .= $char;
            $collectedWidth += $charWidth;
        }

        return $collected;
    }

    private function moveCursorLeft(): ?self
    {
        return $this->cursor > 0 ? $this->cloneWith(cursor: $this->cursor - 1) : null;
    }

    private function moveCursorRight(): ?self
    {
        return $this->cursor < self::graphemeLength($this->value) ? $this->cloneWith(cursor: $this->cursor + 1) : null;
    }

    private function insertCharacter(string $character): ?self
    {
        if ($this->charLimit > 0 && self::graphemeLength($this->value) >= $this->charLimit) {
            return null;
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor);
        $after = self::graphemeSubstring($this->value, $this->cursor);

        return $this->cloneWith(value: $before . $character . $after, cursor: $this->cursor + 1);
    }

    private function deleteCharBackward(): ?self
    {
        if ($this->cursor === 0) {
            return null;
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor - 1);
        $after = self::graphemeSubstring($this->value, $this->cursor);

        return $this->cloneWith(value: $before . $after, cursor: $this->cursor - 1);
    }

    private function deleteCharForward(): ?self
    {
        if ($this->cursor >= self::graphemeLength($this->value)) {
            return null;
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor);
        $after = self::graphemeSubstring($this->value, $this->cursor + 1);

        return $this->cloneWith(value: $before . $after);
    }

    private function deleteWordBackward(): ?self
    {
        if ($this->cursor === 0) {
            return null;
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor);
        $after = self::graphemeSubstring($this->value, $this->cursor);

        $trimmed = rtrim($before);
        $lastSpace = mb_strrpos($trimmed, ' ');
        $newCursor = $lastSpace === false ? 0 : $lastSpace + 1;
        $newBefore = self::graphemeSubstring($before, 0, $newCursor);

        return $this->cloneWith(value: $newBefore . $after, cursor: $newCursor);
    }

    private function deleteWordForward(): ?self
    {
        $textLength = self::graphemeLength($this->value);
        if ($this->cursor >= $textLength) {
            return null;
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor);
        $after = self::graphemeSubstring($this->value, $this->cursor);

        $trimmed = ltrim($after);
        $firstSpace = mb_strpos($trimmed, ' ');
        $deleteCount = $firstSpace === false
            ? self::graphemeLength($after) : self::graphemeLength($after) - self::graphemeLength($trimmed) + $firstSpace;

        $remaining = self::graphemeSubstring($after, $deleteCount);

        return $this->cloneWith(value: $before . $remaining);
    }

    private function computeViewportOffset(int $availableWidth): int
    {
        if ($availableWidth <= 0) {
            return 0;
        }

        $displayText = $this->echoMode->mask($this->value);
        $cursorDisplayWidth = CharWidth::stringWidth(self::graphemeSubstring($displayText, 0, $this->cursor));

        return ScrollWindow::showing(
            $this->viewportOffset,
            $cursorDisplayWidth,
            1,
            $availableWidth,
            ScrollWindow::lastOffset(CharWidth::stringWidth($displayText), $availableWidth),
        );
    }

    private static function graphemeLength(string $value): int
    {
        $length = grapheme_strlen($value);

        return is_int($length) ? $length : 0;
    }

    private static function graphemeSubstring(string $value, int $offset, ?int $length = null): string
    {
        $substring = $length === null
            ? grapheme_substr($value, $offset)
            : grapheme_substr($value, $offset, $length);

        return is_string($substring) ? $substring : '';
    }
}
