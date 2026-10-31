<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Text\GraphemeIndex;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;

final readonly class TextInput implements BindsForTheMoment, Focusable, HoldsAValue, KeybindingsAware, PublishesHints, TakesText
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private function __construct(
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
        private ?int $width = null,
        private ?Message $submit = null,
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

    public static function new(string $value = ''): self
    {
        return (new self())->withValue($value);
    }

    public function momentaryKeybindings(): Keybindings
    {
        return $this->submit === null ? Keybindings::empty() : new Keybindings([Actions::SELECT => ['enter']]);
    }

    public function withSubmit(Message $message): self
    {
        return $this->cloneWith(submit: $message);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            ...$this->submit === null ? [] : ['submit' => Actions::SELECT],
            ...$this->value === '' ? [] : ['clear' => Actions::CLEAR_LINE],
        ]);
    }

    public function takesText(): bool
    {
        return true;
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

    public function withValue(string|bool|array|null $value): static
    {
        $text = match (true) {
            \is_string($value) => $value,
            $value === null => '',
            default => throw ValueItCannotHold::given($this, $value),
        };

        return $this->cloneWith(value: $text, cursor: self::graphemeLength($text));
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
     * Default to a block caret for visibility.
     */
    public function withCursorShape(CursorShape $shape): self
    {
        return $this->cloneWith(cursorShape: $shape);
    }

    public function withDecoration(InputDecoration $decoration): self
    {
        return $this->cloneWith(decoration: $decoration);
    }

    public function withStyled(bool $styled = true): self
    {
        return $this->cloneWith(decoration: $styled ? InputDecoration::Filled : InputDecoration::None);
    }

    public function withWidth(?int $columns): self
    {
        return $this->cloneWith(width: $columns);
    }

    public function sizing(): Sizing
    {
        return Sizing::of($this->width === null ? Constraint::flex() : Constraint::max($this->width), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $area = $this->narrowed($area);

        return match (true) {
            $message instanceof KeyMsg => $this->handleKey($message, $area),
            $message instanceof PasteMsg => new UpdateResult($this->pasted($message->content) ?? $this),
            $message instanceof MouseMsg && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $area = $this->narrowed($area);
        if ($area->width <= 0 || $area->height < 1) {
            return;
        }

        $displayText = $this->echoMode->mask($this->value);
        $textLength = self::graphemeLength($displayText);
        $availableWidth = $area->width;

        $scrollOffset = $this->computeScrollOffset($availableWidth);

        $showPlaceholder = $textLength === 0 && $this->placeholder !== '';

        // Use one text style across the field, including its empty tail, to keep the underline uniform.
        $fieldStyle = $this->fieldStyle($context, $showPlaceholder);
        $fieldStyle->apply($frame, new Rect($area->x, $area->y, $availableWidth, 1));

        $content = $showPlaceholder
            ? CharWidth::truncateToWidth($this->placeholder, $availableWidth)
            : self::substringByDisplayWidth($displayText, $scrollOffset, $availableWidth);

        $frame->putStyled($area->x, $area->y, $content, $fieldStyle);

        if ($this->drawsFocused($context)) {
            $cursorDisplayX = self::prefixWidth($displayText, $this->cursor);
            $cursorScreenX = $area->x + $cursorDisplayX - $scrollOffset;
            if ($cursorScreenX >= $area->x && $cursorScreenX < $area->right()) {
                $frame->setCursorPosition($cursorScreenX, $area->y, $this->cursorShape);
            }
        }
    }

    private function narrowed(Rect $area): Rect
    {
        return $this->width === null || $area->width <= $this->width
            ? $area
            : new Rect($area->x, $area->y, $this->width, $area->height);
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
        if ($this->submit !== null && $bindings->matches($message, Actions::SELECT)) {
            return new UpdateResult($this, Cmd::message($this->submit));
        }

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
        $newCursor = self::isPlain($displayText)
            ? min($clickDisplayX, strlen($displayText))
            : GraphemeIndex::of($displayText)->lastUpTo($clickDisplayX);

        return new UpdateResult($this->cloneWith(cursor: $newCursor));
    }

    private static function substringByDisplayWidth(string $text, int $offsetColumns, int $maxColumns): string
    {
        if (self::isPlain($text)) {
            return substr($text, $offsetColumns, $maxColumns);
        }

        $index = GraphemeIndex::of($text);
        $first = $index->firstFrom($offsetColumns);
        $end = $index->lastUpTo($index->columnOf($first) + $maxColumns);

        return substr($text, $index->byteOf($first), $index->byteOf($end) - $index->byteOf($first));
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
        // Replace pasted line breaks and tabs with spaces because this is a single-line field.
        $content = preg_replace('/[\x00-\x1F\x7F-\x{9F}]+/u', ' ', $content) ?? '';

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
        $cursorDisplayWidth = self::prefixWidth($displayText, $this->cursor);

        return self::graphemeStartAtOrAfter($displayText, ScrollWindow::showing(
            $this->scrollOffset,
            $cursorDisplayWidth,
            1,
            $availableWidth,
            ScrollWindow::lastOffset(self::displayWidth($displayText), $availableWidth),
        ));
    }

    /**
     * Drawing skips a wide character as a whole, so the caret and clicks must count from where it ends too.
     */
    private static function graphemeStartAtOrAfter(string $text, int $column): int
    {
        if (self::isPlain($text)) {
            return min($column, strlen($text));
        }

        $index = GraphemeIndex::of($text);

        return $index->columnOf($index->firstFrom($column));
    }

    private static function graphemeLength(string $value): int
    {
        return self::isPlain($value) ? strlen($value) : GraphemeIndex::of($value)->count;
    }

    private static function graphemeSubstring(string $value, int $offset, ?int $length = null): string
    {
        if (self::isPlain($value)) {
            return substr($value, $offset, $length);
        }

        $index = GraphemeIndex::of($value);
        $first = min($offset, $index->count);
        $end = $length === null ? $index->count : min($first + $length, $index->count);

        return substr($value, $index->byteOf($first), $index->byteOf($end) - $index->byteOf($first));
    }

    /**
     * In printable ASCII every byte is one grapheme one column wide, so byte offsets are the answer.
     *
     * @infection-ignore-all a memo of the last answer: the same without it, only slower
     */
    private static function isPlain(string $text): bool
    {
        static $last = null;
        static $answer = false;
        if ($text !== $last) {
            $last = $text;
            $answer = self::holdsOnlyPrintableAscii($text);
        }

        return $answer;
    }

    private static function holdsOnlyPrintableAscii(string $text): bool
    {
        return preg_match('/[^\x20-\x7e]/', $text) !== 1;
    }

    private static function displayWidth(string $text): int
    {
        return self::isPlain($text) ? strlen($text) : GraphemeIndex::of($text)->width();
    }

    private static function prefixWidth(string $text, int $graphemes): int
    {
        if (self::isPlain($text)) {
            return min($graphemes, strlen($text));
        }

        $index = GraphemeIndex::of($text);

        return $index->columnOf(min($graphemes, $index->count));
    }
}
