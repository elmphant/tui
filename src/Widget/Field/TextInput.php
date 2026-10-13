<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Grapheme;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class TextInput implements Focusable, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    public function __construct(
        private string $value = '',
        private int $cursor = 0,
        private int $scrollOffset = 0,
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
            Actions::WORD_LEFT => ['alt+left', 'ctrl+left', 'alt+b'],
            Actions::WORD_RIGHT => ['alt+right', 'ctrl+right', 'alt+f'],
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

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(
            $this->value === '' ? [] : ['clear' => Actions::CLEAR_LINE],
        );
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
        return $this->cloneWith(cursor: max(0, min($cursor, self::graphemeLength($this->value))));
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
     * Draw the theme's input background across the field instead of underlining it.
     */
    public function withStyled(bool $styled = true): self
    {
        return $this->cloneWith(decoration: $styled ? InputDecoration::Filled : InputDecoration::None);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg => $this->handleKey($message, $area),
            $message instanceof PasteMsg => new UpdateResult($this->pasted($message->content) ?? $this),
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

        $scrollOffset = $this->computeScrollOffset($availableWidth);

        $showPlaceholder = $textLength === 0 && $this->placeholder !== '';

        // Terminals colour the underline from the text, so the whole field —
        // empty tail included — has to carry one style.
        $fieldStyle = $this->fieldStyle($context, $showPlaceholder);
        $fieldStyle->apply($frame, new Rect($area->x, $area->y, $availableWidth, 1));

        $content = $showPlaceholder
            ? CharWidth::truncateToWidth($this->placeholder, $availableWidth)
            : self::substringByDisplayWidth($displayText, $scrollOffset, $availableWidth);

        $frame->putStyled($area->x, $area->y, $content, $fieldStyle);

        if ($this->drawsFocused($context)) {
            $cursorDisplayX = CharWidth::stringWidth(self::graphemeSubstring($displayText, 0, $this->cursor));
            $cursorScreenX = $area->x + $cursorDisplayX - $scrollOffset;
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
        $tone = $showPlaceholder || !$this->drawsFocused($context) ? $theme->dim : $theme->base;

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
            $bindings->matches($message, Actions::WORD_LEFT) => $this->moveCursorTo(self::wordStartBefore(Grapheme::split($this->value), $this->cursor)),
            $bindings->matches($message, Actions::WORD_RIGHT) => $this->moveCursorTo(self::wordEndAfter(Grapheme::split($this->value), $this->cursor)),
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
            $scrollOffset = $updated->computeScrollOffset($area->width);
            if ($scrollOffset !== $updated->scrollOffset) {
                $updated = $updated->cloneWith(scrollOffset: $scrollOffset);
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
        $scrollOffset = $this->computeScrollOffset($area->width);
        $clickDisplayX = $message->x - $area->x + $scrollOffset;
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

    private function moveCursorTo(int $cursor): ?self
    {
        return $cursor === $this->cursor ? null : $this->cloneWith(cursor: $cursor);
    }

    /**
     * @param list<string> $graphemes
     */
    private static function wordStartBefore(array $graphemes, int $cursor): int
    {
        while ($cursor > 0 && self::wordKindOf($graphemes[$cursor - 1]) === 'space') {
            --$cursor;
        }

        $kind = $cursor > 0 ? self::wordKindOf($graphemes[$cursor - 1]) : null;
        while ($cursor > 0 && self::wordKindOf($graphemes[$cursor - 1]) === $kind) {
            --$cursor;
        }

        return $cursor;
    }

    /**
     * @param list<string> $graphemes
     */
    private static function wordEndAfter(array $graphemes, int $cursor): int
    {
        $length = count($graphemes);
        while ($cursor < $length && self::wordKindOf($graphemes[$cursor]) === 'space') {
            ++$cursor;
        }

        $kind = $cursor < $length ? self::wordKindOf($graphemes[$cursor]) : null;
        while ($cursor < $length && self::wordKindOf($graphemes[$cursor]) === $kind) {
            ++$cursor;
        }

        return $cursor;
    }

    /**
     * @return 'space'|'word'|'punctuation'
     */
    private static function wordKindOf(string $grapheme): string
    {
        return match (true) {
            preg_match('/^\s/u', $grapheme) === 1 => 'space',
            preg_match('/^[\p{L}\p{N}\p{M}_]/u', $grapheme) === 1 => 'word',
            default => 'punctuation',
        };
    }

    private function insertCharacter(string $character): ?self
    {
        if ($this->charLimit > 0 && self::graphemeLength($this->value) >= $this->charLimit) {
            return null;
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor);
        $after = self::graphemeSubstring($this->value, $this->cursor);

        return $this->cloneWith(value: $before . $character . $after, cursor: self::graphemeLength($before . $character));
    }

    private function pasted(string $content): ?self
    {
        // One line, so a break or a tab from the clipboard becomes a space rather than
        // a control character drawn as a hole and submitted as part of the answer.
        $content = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $content) ?? '';

        if ($this->charLimit > 0) {
            $room = $this->charLimit - self::graphemeLength($this->value);

            if ($room <= 0) {
                return null;
            }

            $content = self::graphemeSubstring($content, 0, $room);
        }

        $before = self::graphemeSubstring($this->value, 0, $this->cursor);
        $after = self::graphemeSubstring($this->value, $this->cursor);

        return $this->cloneWith(
            value: $before . $content . $after,
            cursor: self::graphemeLength($before . $content),
        );
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

        $graphemes = Grapheme::split($this->value);
        $newCursor = self::wordStartBefore($graphemes, $this->cursor);

        return $this->cloneWith(
            value: implode('', array_slice($graphemes, 0, $newCursor)) . implode('', array_slice($graphemes, $this->cursor)),
            cursor: $newCursor,
        );
    }

    private function deleteWordForward(): ?self
    {
        $graphemes = Grapheme::split($this->value);
        if ($this->cursor >= count($graphemes)) {
            return null;
        }

        $wordEnd = self::wordEndAfter($graphemes, $this->cursor);

        return $this->cloneWith(
            value: implode('', array_slice($graphemes, 0, $this->cursor)) . implode('', array_slice($graphemes, $wordEnd)),
        );
    }

    private function computeScrollOffset(int $availableWidth): int
    {
        if ($availableWidth <= 0) {
            return 0;
        }

        $displayText = $this->echoMode->mask($this->value);
        $cursorDisplayWidth = CharWidth::stringWidth(self::graphemeSubstring($displayText, 0, $this->cursor));

        return ScrollWindow::showing(
            $this->scrollOffset,
            $cursorDisplayWidth,
            1,
            $availableWidth,
            ScrollWindow::lastOffset(CharWidth::stringWidth($displayText), $availableWidth),
        );
    }

    private static function graphemeLength(string $value): int
    {
        return count(Grapheme::split($value));
    }

    private static function graphemeSubstring(string $value, int $offset, ?int $length = null): string
    {
        return implode('', array_slice(Grapheme::split($value), $offset, $length));
    }
}
