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
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;

final readonly class TextInput implements BindsForTheMoment, Committing, Focusable, HoldsAValue, KeybindingsAware, PublishesHints, TakesText
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
        private bool $submits = false,
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
        return $this->submits ? new Keybindings([Actions::SELECT => ['enter']]) : Keybindings::empty();
    }

    /**
     * Enter submits: the field commits, so a bound host hears it, and sends the message when there is one.
     * A field left without it hands Enter to what encloses it, as a form moves on with it.
     */
    public function withSubmit(?Message $message = null): self
    {
        return $this->cloneWith(submits: true, submit: $message);
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        return $this->submits
            && $message instanceof KeyMsg
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            ? new Submitted($this->value, $message)
            : null;
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            ...$this->submits ? ['submit' => Actions::SELECT] : [],
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
        return $this->cloneWith(cursor: max(0, min($cursor, LineText::length($this->value))));
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

        return $this->cloneWith(value: $text, cursor: LineText::length($text));
    }

    public function withCharacterLimit(int $limit): self
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
            $message instanceof MouseMsg && $message->isClick() && $message->isIn($area) => $this->handleClick($message, $area),
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
        $textLength = LineText::length($displayText);
        $availableWidth = $area->width;

        $scrollOffset = $this->computeScrollOffset($availableWidth);

        $showPlaceholder = $textLength === 0 && $this->placeholder !== '';

        // Use one text style across the field, including its empty tail, to keep the underline uniform.
        $fieldStyle = $this->fieldStyle($context, $showPlaceholder);
        $frame->fillStyled(new Rect($area->x, $area->y, $availableWidth, 1), $fieldStyle);

        $content = $showPlaceholder
            ? CharWidth::truncateToWidth($this->placeholder, $availableWidth)
            : LineText::slice($displayText, $scrollOffset, $availableWidth);

        $frame->putStyled($area->x, $area->y, $content, $fieldStyle);

        if ($this->drawsFocused($context)) {
            $cursorDisplayX = LineText::prefixWidth($displayText, $this->cursor);
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
                ? $tone->withBackground($theme->inputStyle->background)
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
        if ($this->submits && $bindings->matches($message, Actions::SELECT)) {
            return new UpdateResult($this, $this->submit === null ? null : Cmd::message($this->submit));
        }

        $updated = match (true) {
            $bindings->matches($message, Actions::CURSOR_LEFT) => $this->moveCursorLeft(),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => $this->moveCursorRight(),
            $bindings->matches($message, Actions::WORD_LEFT) => $this->moveCursorTo(LineText::wordStartBefore(Grapheme::split($this->value), $this->cursor)),
            $bindings->matches($message, Actions::WORD_RIGHT) => $this->moveCursorTo(LineText::wordEndAfter(Grapheme::split($this->value), $this->cursor)),
            $bindings->matches($message, Actions::LINE_START) => $this->cursor > 0 ? $this->cloneWith(cursor: 0) : null,
            $bindings->matches($message, Actions::LINE_END) => $this->cursor < LineText::length($this->value) ? $this->cloneWith(cursor: LineText::length($this->value)) : null,
            $bindings->matches($message, Actions::CLEAR_LINE) => $this->value === '' ? null : $this->cloneWith(value: '', cursor: 0),
            $bindings->matches($message, Actions::DELETE_WORD_BACKWARD) => $this->deleteWordBackward(),
            $bindings->matches($message, Actions::DELETE_CHAR_BACKWARD) => $this->deleteCharBackward(),
            $bindings->matches($message, Actions::DELETE_WORD_FORWARD) => $this->deleteWordForward(),
            $bindings->matches($message, Actions::DELETE_CHAR_FORWARD) => $this->deleteCharForward(),
            is_string($message->key) && !$message->ctrl && !$message->alt && LineText::length($message->key) === 1 => $this->insertCharacter($message->key),
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

        return new UpdateResult($this->cloneWith(cursor: LineText::graphemeAt($displayText, $clickDisplayX)));
    }

    private function moveCursorLeft(): ?self
    {
        return $this->cursor > 0 ? $this->cloneWith(cursor: $this->cursor - 1) : null;
    }

    private function moveCursorRight(): ?self
    {
        return $this->cursor < LineText::length($this->value) ? $this->cloneWith(cursor: $this->cursor + 1) : null;
    }

    private function moveCursorTo(int $cursor): ?self
    {
        return $cursor === $this->cursor ? null : $this->cloneWith(cursor: $cursor);
    }

    private function insertCharacter(string $character): ?self
    {
        if ($this->charLimit > 0 && LineText::length($this->value) >= $this->charLimit) {
            return null;
        }

        $before = LineText::substring($this->value, 0, $this->cursor);
        $after = LineText::substring($this->value, $this->cursor);

        return $this->cloneWith(value: $before . $character . $after, cursor: LineText::length($before . $character));
    }

    private function pasted(string $content): ?self
    {
        // Replace pasted line breaks and tabs with spaces because this is a single-line field.
        $content = preg_replace('/[\x00-\x1F\x7F-\x{9F}]+/u', ' ', $content) ?? '';

        if ($this->charLimit > 0) {
            $room = $this->charLimit - LineText::length($this->value);

            if ($room <= 0) {
                return null;
            }

            $content = LineText::substring($content, 0, $room);
        }

        $before = LineText::substring($this->value, 0, $this->cursor);
        $after = LineText::substring($this->value, $this->cursor);

        return $this->cloneWith(
            value: $before . $content . $after,
            cursor: LineText::length($before . $content),
        );
    }

    private function deleteCharBackward(): ?self
    {
        if ($this->cursor === 0) {
            return null;
        }

        $before = LineText::substring($this->value, 0, $this->cursor - 1);
        $after = LineText::substring($this->value, $this->cursor);

        return $this->cloneWith(value: $before . $after, cursor: $this->cursor - 1);
    }

    private function deleteCharForward(): ?self
    {
        if ($this->cursor >= LineText::length($this->value)) {
            return null;
        }

        $before = LineText::substring($this->value, 0, $this->cursor);
        $after = LineText::substring($this->value, $this->cursor + 1);

        return $this->cloneWith(value: $before . $after);
    }

    private function deleteWordBackward(): ?self
    {
        if ($this->cursor === 0) {
            return null;
        }

        $graphemes = Grapheme::split($this->value);
        $newCursor = LineText::wordStartBefore($graphemes, $this->cursor);

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

        $wordEnd = LineText::wordEndAfter($graphemes, $this->cursor);

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
        $cursorDisplayWidth = LineText::prefixWidth($displayText, $this->cursor);

        return LineText::columnAtOrAfter($displayText, ScrollWindow::showing(
            $this->scrollOffset,
            $cursorDisplayWidth,
            1,
            $availableWidth,
            ScrollWindow::lastOffset(LineText::width($displayText), $availableWidth),
        ));
    }
}
