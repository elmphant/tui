<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\ValueItCannotHold;

final readonly class TextArea implements Focusable, HoldsAValue, KeybindingsAware, TakesText
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const TAB = '    ';

    /**
     * @param non-empty-list<string> $lines
     * @param ?int                   $goalColumn the display column up and down keep while lines are shorter
     */
    private function __construct(
        private array $lines = [''],
        private int $row = 0,
        private int $column = 0,
        private ?int $goalColumn = null,
        private int $top = 0,
        private int $left = 0,
        private string $placeholder = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function new(string $value = ''): self
    {
        return (new self())->withValue($value);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_DOWN => ['down'],
            Actions::WORD_LEFT => ['alt+left', 'ctrl+left', 'alt+b'],
            Actions::WORD_RIGHT => ['alt+right', 'ctrl+right', 'alt+f'],
            Actions::LINE_START => ['home'],
            Actions::LINE_END => ['end'],
            Actions::HOME => ['ctrl+home'],
            Actions::END => ['ctrl+end'],
            Actions::PAGE_UP => ['page_up'],
            Actions::PAGE_DOWN => ['page_down'],
            Actions::NEW_LINE => ['enter'],
            Actions::DELETE_CHAR_BACKWARD => ['backspace'],
            Actions::DELETE_CHAR_FORWARD => ['delete'],
            Actions::DELETE_WORD_BACKWARD => ['ctrl+backspace'],
            Actions::DELETE_WORD_FORWARD => ['ctrl+delete'],
        ]);
    }

    public function takesText(): bool
    {
        return true;
    }

    public function value(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * @return array{int, int} the line, then the grapheme in it
     */
    public function cursor(): array
    {
        return [$this->row, $this->column];
    }

    public function withCursor(int $row, int $column): self
    {
        $row = max(0, min($row, count($this->lines) - 1));

        return $this->cloneWith(row: $row, column: max(0, min($column, LineText::length($this->lines[$row]))), goalColumn: null);
    }

    /**
     * Line breaks of any kind become new lines and a tab four spaces. The cursor goes to the end.
     */
    public function withValue(string|bool|array|null $value): static
    {
        $text = match (true) {
            \is_string($value) => $value,
            $value === null => '',
            default => throw ValueItCannotHold::given($this, $value),
        };
        $lines = self::linesOf($text);
        $row = count($lines) - 1;

        return $this->cloneWith(lines: $lines, row: $row, column: LineText::length($lines[$row]), goalColumn: null);
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(placeholder: $placeholder);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $changed = match (true) {
            $message instanceof KeyMsg => $this->keyed($message, $area),
            $message instanceof PasteMsg => $this->inserted($message->content),
            $message instanceof MouseMsg && $message->isClick() && $message->isIn($area) => $this->clicked($message, $area),
            $message instanceof MouseMsg && $message->action === MouseAction::WheelUp => $this->movedBy(-$message->wheelSteps(3)),
            $message instanceof MouseMsg && $message->action === MouseAction::WheelDown => $this->movedBy($message->wheelSteps(3)),
            default => null,
        };

        return new UpdateResult($changed === null ? $this : $changed->cloneWith(top: $changed->topIn($area->height), left: $changed->leftIn($area->width)));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $focused = $this->drawsFocused($context);
        $style = $this->fieldStyle($context, $context->theme->base);
        $frame->fillStyled($area, $style);

        if ($this->lines === [''] && $this->placeholder !== '') {
            $frame->putStyled($area->x, $area->y, LineText::slice($this->placeholder, 0, $area->width), $this->fieldStyle($context, $context->theme->dim));
        }

        $top = $this->topIn($area->height);
        $left = $this->leftIn($area->width);

        for ($offset = 0; $offset < $area->height && isset($this->lines[$top + $offset]); ++$offset) {
            $frame->putStyled($area->x, $area->y + $offset, LineText::slice($this->lines[$top + $offset], $left, $area->width), $style);
        }

        if ($focused) {
            $x = $area->x + LineText::prefixWidth($this->lines[$this->row], $this->column) - $left;
            if ($x < $area->right()) {
                $frame->setCursorPosition($x, $area->y + $this->row - $top, CursorShape::BlinkingBlock);
            }
        }
    }

    /**
     * @return non-empty-list<string>
     */
    private static function linesOf(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", self::TAB], $text);

        return explode("\n", preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $text) ?? '');
    }

    private function fieldStyle(RenderContext $context, Style $tone): Style
    {
        $background = $context->theme->inputStyle->background;

        return $background === null ? $tone : $tone->withBackground($background);
    }

    private function keyed(KeyMsg $message, Rect $area): ?self
    {
        $bindings = $this->effectiveKeybindings();
        $page = max(1, $area->height - 1);

        return match (true) {
            $bindings->matches($message, Actions::NEW_LINE) => $this->inserted("\n"),
            $bindings->matches($message, Actions::CURSOR_LEFT) => $this->movedLeft(),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => $this->movedRight(),
            $bindings->matches($message, Actions::CURSOR_UP) => $this->movedBy(-1),
            $bindings->matches($message, Actions::CURSOR_DOWN) => $this->movedBy(1),
            $bindings->matches($message, Actions::PAGE_UP) => $this->movedBy(-$page),
            $bindings->matches($message, Actions::PAGE_DOWN) => $this->movedBy($page),
            $bindings->matches($message, Actions::WORD_LEFT) => $this->column === 0
                ? $this->movedLeft()
                : $this->at($this->row, LineText::wordStartBefore(Grapheme::split($this->line()), $this->column)),
            $bindings->matches($message, Actions::WORD_RIGHT) => $this->column === LineText::length($this->line())
                ? $this->movedRight()
                : $this->at($this->row, LineText::wordEndAfter(Grapheme::split($this->line()), $this->column)),
            $bindings->matches($message, Actions::LINE_START) => $this->at($this->row, 0),
            $bindings->matches($message, Actions::LINE_END) => $this->at($this->row, LineText::length($this->line())),
            $bindings->matches($message, Actions::HOME) => $this->at(0, 0),
            $bindings->matches($message, Actions::END) => $this->at(count($this->lines) - 1, LineText::length($this->lines[count($this->lines) - 1])),
            $bindings->matches($message, Actions::DELETE_WORD_BACKWARD) => $this->column === 0
                ? $this->deletedBackward()
                : $this->withoutColumns(LineText::wordStartBefore(Grapheme::split($this->line()), $this->column), $this->column),
            $bindings->matches($message, Actions::DELETE_CHAR_BACKWARD) => $this->deletedBackward(),
            $bindings->matches($message, Actions::DELETE_WORD_FORWARD) => $this->column === LineText::length($this->line())
                ? $this->deletedForward()
                : $this->withoutColumns($this->column, LineText::wordEndAfter(Grapheme::split($this->line()), $this->column)),
            $bindings->matches($message, Actions::DELETE_CHAR_FORWARD) => $this->deletedForward(),
            is_string($message->key) && !$message->ctrl && !$message->alt && LineText::length($message->key) === 1 => $this->inserted($message->key),
            default => null,
        };
    }

    private function line(): string
    {
        return $this->lines[$this->row];
    }

    private function at(int $row, int $column): ?self
    {
        return $row === $this->row && $column === $this->column && $this->goalColumn === null
            ? null
            : $this->cloneWith(row: $row, column: $column, goalColumn: null);
    }

    private function movedLeft(): ?self
    {
        return match (true) {
            $this->column > 0 => $this->at($this->row, $this->column - 1),
            $this->row > 0 => $this->at($this->row - 1, LineText::length($this->lines[$this->row - 1])),
            default => null,
        };
    }

    private function movedRight(): ?self
    {
        return match (true) {
            $this->column < LineText::length($this->line()) => $this->at($this->row, $this->column + 1),
            $this->row < count($this->lines) - 1 => $this->at($this->row + 1, 0),
            default => null,
        };
    }

    private function movedBy(int $rows): ?self
    {
        $row = max(0, min($this->row + $rows, count($this->lines) - 1));
        if ($row === $this->row) {
            return null;
        }

        $goal = $this->goalColumn ?? LineText::prefixWidth($this->line(), $this->column);

        return $this->cloneWith(row: $row, column: LineText::graphemeAt($this->lines[$row], $goal), goalColumn: $goal);
    }

    private function inserted(string $text): self
    {
        $pieces = self::linesOf($text);
        $before = LineText::substring($this->line(), 0, $this->column);
        $after = LineText::substring($this->line(), $this->column);
        $last = count($pieces) - 1;
        $column = LineText::length($pieces[$last]) + ($last === 0 ? LineText::length($before) : 0);
        $pieces[0] = $before . $pieces[0];
        $pieces[$last] .= $after;

        $lines = $this->lines;
        array_splice($lines, $this->row, 1, $pieces);

        return $this->cloneWith(lines: $lines, row: $this->row + $last, column: $column, goalColumn: null);
    }

    private function deletedBackward(): ?self
    {
        if ($this->column > 0) {
            return $this->withoutColumns($this->column - 1, $this->column);
        }

        if ($this->row <= 0) {
            return null;
        }

        $above = $this->lines[$this->row - 1];
        $lines = $this->lines;
        array_splice($lines, $this->row - 1, 2, [$above . $this->line()]);

        return $this->cloneWith(lines: $lines, row: $this->row - 1, column: LineText::length($above), goalColumn: null);
    }

    private function deletedForward(): ?self
    {
        if ($this->column < LineText::length($this->line())) {
            return $this->withoutColumns($this->column, $this->column + 1);
        }

        if ($this->row === count($this->lines) - 1) {
            return null;
        }

        $lines = $this->lines;
        array_splice($lines, $this->row, 2, [$this->line() . $this->lines[$this->row + 1]]);

        return $this->cloneWith(lines: $lines, goalColumn: null);
    }

    private function withoutColumns(int $from, int $to): self
    {
        $lines = $this->lines;
        $lines[$this->row] = LineText::substring($this->line(), 0, $from) . LineText::substring($this->line(), $to);

        return $this->cloneWith(lines: $lines, column: $from, goalColumn: null);
    }

    private function clicked(MouseMsg $message, Rect $area): self
    {
        $row = min($this->topIn($area->height) + $message->y - $area->y, count($this->lines) - 1);

        return $this->cloneWith(
            row: $row,
            column: LineText::graphemeAt($this->lines[$row], $message->x - $area->x + $this->leftIn($area->width)),
            goalColumn: null,
        );
    }

    private function topIn(int $height): int
    {
        return $height < 1 ? 0 : ScrollWindow::showing($this->top, $this->row, 1, $height, max(0, count($this->lines) - $height));
    }

    private function leftIn(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        $line = $this->line();

        return LineText::columnAtOrAfter($line, ScrollWindow::showing(
            $this->left,
            LineText::prefixWidth($line, $this->column),
            1,
            $width,
            max(0, LineText::width($line) + 1 - $width),
        ));
    }
}
