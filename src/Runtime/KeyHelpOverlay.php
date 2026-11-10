<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeyHint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Places;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Text\TerminalText;
use Elmphant\Tui\Text\TextWrap;

/**
 * @internal the key help the runtime lays over the screen. Open or closed is the
 * runtime's to know, as an open chord is: it changes no model.
 */
final class KeyHelpOverlay
{
    public const MOVES = [Actions::CURSOR_UP, Actions::CURSOR_DOWN, Actions::PAGE_UP, Actions::PAGE_DOWN, Actions::HOME, Actions::END];

    private const TITLE = 'Keys';
    private const PAGE = 10;

    /**
     * The filter row, a gap, a gap and the hints.
     */
    private const CHROME_ROWS = 4;

    private bool $open = false;
    private string $query = '';
    private bool $searching = false;
    private ?string $cursorKey = null;
    private int $cursorNear = 0;
    private int $top = 0;

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Null when the help took the message.
     */
    public function handOn(Message $message, object $model, FocusState $focus): ?Message
    {
        if (!$model instanceof DescribesKeys || (!$message instanceof KeyMsg && !$message instanceof MouseMsg && !$message instanceof PasteMsg)) {
            return $message;
        }

        if (!$this->open) {
            if ($message instanceof KeyMsg && $model->opensKeyHelp($message, $focus)) {
                $this->openAnew();

                return null;
            }

            return $message;
        }

        $entries = KeyHelpRows::of($model->keyHelp($focus), $this->query)->entries();
        $keys = $model->keyHelpKeys();

        if ($message instanceof PasteMsg) {
            if ($this->fieldHoldsTheKeys($keys)) {
                $this->typed(TerminalText::singleLine($message->content));
            }

            return null;
        }

        if ($message instanceof MouseMsg) {
            $this->moveBy($entries, match ($message->action) {
                MouseAction::WheelUp => -1,
                MouseAction::WheelDown => 1,
                default => 0,
            });

            return null;
        }

        return $this->pressed($message, $model, $focus, $entries, $keys);
    }

    /**
     * @param Rect $screen where the model drew. The help may grow it downwards as far as the frame goes.
     */
    public function drawOver(Frame $frame, RenderContext $context, object $model, FocusState $focus, Rect $screen): void
    {
        if (!$this->open || !$model instanceof DescribesKeys) {
            return;
        }

        $groups = $model->keyHelp($focus);
        $all = KeyHelpRows::of($groups, '');
        $shown = $this->query === '' ? $all : KeyHelpRows::of($groups, $this->query);
        $keys = $model->keyHelpKeys();
        $field = $this->fieldHoldsTheKeys($keys);
        $filter = $this->filterText($keys, $field);
        $hints = $this->hints($keys, $this->runnableAtCursor($shown->entries()));

        $content = max($all->width(), CharWidth::stringWidth($this->hints($keys, true)), CharWidth::stringWidth($filter) + 1, CharWidth::stringWidth(self::TITLE));
        $needed = \count($all->lines) + self::CHROME_ROWS;
        $stageHeight = min($frame->height, max($screen->height, $needed + 4));
        $width = min($frame->width - 2, $content + 4);
        $height = min($stageHeight - 2, $needed + 2);
        if ($width < 3 || $height < 3) {
            return;
        }

        $area = new Rect(intdiv($frame->width - $width, 2), intdiv($stageHeight - $height, 2), $width, $height);
        $frame->fillStyled($area, $context->theme->base);
        Block::new()
            ->withBorderType(BorderType::Rounded)
            ->withTitle(self::TITLE)
            ->withBorderStyle($context->theme->focusedBorderStyle)
            ->withTitleStyle($context->theme->title)
            ->render($frame, $area);

        $inner = new Rect($area->x + 2, $area->y + 1, max(0, $width - 4), $height - 2);
        if ($inner->width > 0) {
            $this->drawFilter($frame, $context, $inner, $filter, $field);
            $list = new Rect($inner->x, $inner->y + 2, $inner->width, max(0, $inner->height - self::CHROME_ROWS));
            $this->drawList($frame, $context, $list, $shown);
            if ($inner->height >= self::CHROME_ROWS) {
                $frame->putStyled($inner->x, $inner->y + $inner->height - 1, CharWidth::truncateToWidth($hints, $inner->width), $context->theme->dim);
            }
        }

        $frame->clearCursor();
    }

    private function openAnew(): void
    {
        $this->open = true;
        $this->query = '';
        $this->searching = false;
        $this->cursorKey = null;
        $this->cursorNear = 0;
        $this->top = 0;
    }

    private function fieldHoldsTheKeys(Keybindings $keys): bool
    {
        return $this->searching || $keys->patternsOf(Actions::SEARCH) === [];
    }

    private function typed(string $text): void
    {
        $this->queried($this->query . $text);
    }

    private function queried(string $query): void
    {
        $this->query = $query;
        $this->cursorKey = null;
        $this->cursorNear = 0;
        $this->top = 0;
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function moveBy(array $entries, int $rows): void
    {
        $cursor = $this->cursorIn($entries);
        if ($cursor === null) {
            return;
        }

        $this->placeCursor($entries, max(0, min(\count($entries) - 1, $cursor + $rows)));
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function cursorIn(array $entries): ?int
    {
        if ($entries === []) {
            return null;
        }

        return Places::cursorAfter($this->cursorKey, array_map(self::keyOf(...), $entries), $this->cursorNear);
    }

    private static function keyOf(KeyHint $entry): string
    {
        return $entry->label . "\0" . $entry->description;
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function runnableAtCursor(array $entries): bool
    {
        $cursor = $this->cursorIn($entries);

        return $cursor !== null && $entries[$cursor]->key !== null;
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function placeCursor(array $entries, int $index): void
    {
        $this->cursorKey = self::keyOf($entries[$index]);
        $this->cursorNear = $index;
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function pressed(KeyMsg $message, DescribesKeys $model, FocusState $focus, array $entries, Keybindings $keys): ?Message
    {
        $field = $this->fieldHoldsTheKeys($keys);

        if ($field && $message->isText() && \is_string($message->key)) {
            $this->typed($message->key);

            return null;
        }

        if (!$field && $keys->matches($message, Actions::SEARCH)) {
            $this->searching = true;

            return null;
        }

        if ($model->opensKeyHelp($message, $focus) || (!$field && $message->key === '?')) {
            $this->open = false;

            return null;
        }

        $move = Keybindings::rowNavigation()->plus($keys)->restrictedTo(...self::MOVES)->actionOf($message);

        return match (true) {
            $message->key === Key::Escape => $this->escaped(),
            $message->key === Key::Enter => $this->entered($entries, $message),
            $message->key === Key::Backspace && $field => $this->erased(),
            $move !== null => $this->moved($entries, $move),
            $message->ctrl || $message->alt => $this->closedFor($message),
            default => null,
        };
    }

    private function escaped(): null
    {
        if ($this->searching) {
            $this->searching = false;
        } elseif ($this->query !== '') {
            $this->queried('');
        } else {
            $this->open = false;
        }

        return null;
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function entered(array $entries, KeyMsg $enter): ?KeyMsg
    {
        if ($this->searching) {
            $this->searching = false;

            return null;
        }

        $cursor = $this->cursorIn($entries);
        $key = $cursor === null ? null : $entries[$cursor]->key;
        if ($key === null) {
            return null;
        }

        $this->open = false;
        $pressed = $key->pressed();

        return $enter->screen === null ? $pressed : $pressed->withScreen($enter->screen);
    }

    private function erased(): null
    {
        $this->queried(implode('', \array_slice(Grapheme::split($this->query), 0, -1)));

        return null;
    }

    private function closedFor(KeyMsg $message): KeyMsg
    {
        $this->open = false;

        return $message;
    }

    /**
     * @param list<KeyHint> $entries
     */
    private function moved(array $entries, string $action): null
    {
        $this->moveBy($entries, match ($action) {
            Actions::CURSOR_UP => -1,
            Actions::CURSOR_DOWN => 1,
            Actions::PAGE_UP => -self::PAGE,
            Actions::PAGE_DOWN => self::PAGE,
            Actions::HOME => -\PHP_INT_MAX >> 1,
            default => \PHP_INT_MAX >> 1,
        });

        return null;
    }

    private function filterText(Keybindings $keys, bool $field): string
    {
        $searchKey = $keys->label(Actions::SEARCH);

        return match (true) {
            $searchKey === null => '> ' . $this->query,
            !$field && $this->query === '' => $searchKey . ' search',
            default => $searchKey . ' ' . $this->query,
        };
    }

    private function hints(Keybindings $keys, bool $runnable): string
    {
        if ($this->searching) {
            return '↑/↓ choose  Enter/Esc done';
        }

        $searchKey = $keys->label(Actions::SEARCH);

        return implode('  ', [
            '↑/↓ choose',
            ...($runnable ? ['Enter run'] : []),
            ...($searchKey === null ? [] : [$searchKey . ' search']),
            $this->query === '' ? 'Esc close' : 'Esc clear',
        ]);
    }

    private function drawFilter(Frame $frame, RenderContext $context, Rect $inner, string $filter, bool $field): void
    {
        $placeholder = !$field && $this->query === '';
        $room = $field ? $inner->width - 1 : $inner->width;
        $shown = CharWidth::stringWidth($filter) <= $room ? $filter : self::endOf($filter, $room);
        $frame->putStyled($inner->x, $inner->y, CharWidth::truncateToWidth($shown, $inner->width), $placeholder ? $context->theme->dim : $context->theme->base);

        $caret = CharWidth::stringWidth($shown);
        if ($field && $caret < $inner->width) {
            $frame->toggleAttribute($inner->x + $caret, $inner->y, 1, Attr::REVERSE);
        }
    }

    private static function endOf(string $filter, int $room): string
    {
        $prefix = substr($filter, 0, (int)strpos($filter, ' ') + 1);
        $tail = '';
        foreach (array_reverse(Grapheme::split(substr($filter, \strlen($prefix)))) as $grapheme) {
            if (CharWidth::stringWidth($prefix . '…' . $grapheme . $tail) > $room) {
                break;
            }
            $tail = $grapheme . $tail;
        }

        return $prefix . '…' . $tail;
    }

    private function drawList(Frame $frame, RenderContext $context, Rect $list, KeyHelpRows $shown): void
    {
        $entries = $shown->entries();
        $cursor = $this->cursorIn($entries);
        if ($cursor === null) {
            if ($this->query !== '' && $list->height > 0) {
                $frame->putStyled($list->x + KeyHelpRows::MARKER_WIDTH, $list->y, CharWidth::truncateToWidth('No key matches "' . $this->query . '"', $list->width - KeyHelpRows::MARKER_WIDTH), $context->theme->dim);
            }

            return;
        }

        $current = $entries[$cursor];
        $this->placeCursor($entries, $cursor);
        $this->top = min($this->top, $shown->firstLineShowing($current));
        $this->top = max($this->top, $shown->lineOf($current) - $list->height + 1);
        $this->top = max(0, min($this->top, \count($shown->lines) - $list->height));

        foreach (\array_slice($shown->lines, $this->top, $list->height) as $row => $line) {
            $y = $list->y + $row;
            if (\is_string($line)) {
                $frame->putStyled($list->x, $y, CharWidth::truncateToWidth($line, $list->width), $context->theme->accent->bold());
            } elseif ($line instanceof KeyHint) {
                self::drawEntry($frame, $context, new Rect($list->x, $y, $list->width, 1), $line, $line === $current);
            }
        }
    }

    private static function drawEntry(Frame $frame, RenderContext $context, Rect $row, KeyHint $entry, bool $atCursor): void
    {
        $style = $atCursor ? $context->theme->cursorStyle : $context->theme->base;
        if ($atCursor) {
            $frame->fillStyled($row, $style);
            $frame->putStyled($row->x, $row->y, '›', $style);
        }

        $labelWidth = min(CharWidth::stringWidth($entry->label), $row->width);
        $room = $row->width - KeyHelpRows::MARKER_WIDTH - KeyHelpRows::GAP - $labelWidth;
        if ($room > 0) {
            $frame->putStyled($row->x + KeyHelpRows::MARKER_WIDTH, $row->y, TextWrap::ellipsize($entry->description, $room), $style);
        }
        $frame->putStyled($row->x + $row->width - $labelWidth, $row->y, CharWidth::truncateToWidth($entry->label, $labelWidth), $style);
    }
}
