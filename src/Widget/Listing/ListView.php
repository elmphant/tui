<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\RowExtents;
use Elmphant\Tui\Layout\RowIndex;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\Places;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class ListView implements Committing, CursorAware, Focusable, KeybindingsAware, MeasureHeight, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const SCROLLBAR_GUTTER = 2;

    /**
     * Share measurements across clones; only replacing items invalidates them.
     */
    private RowIndex $rowIndex;

    /**
     * @param list<ListItem> $items
     */
    private function __construct(
        private array $items = [],
        private int $cursor = 0,
        private ?int $selected = null,
        private int $scrollOffset = 0,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $followCursor = true,
        private int $scrollPadding = 0,
        ?RowIndex $rowIndex = null,
    ) {
        $this->rowIndex = $rowIndex ?? RowIndex::of(count($items));
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::rowNavigation();
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'choose' => Actions::SELECT,
        ]);
    }

    /**
     * @param list<ListItem> $items
     */
    public static function new(array $items): self
    {
        ListItems::assertAll($items, 'ListView');

        return new self(
            items: $items,
        );
    }

    /**
     * Skip validation for items already checked, since filtering replaces them on each keystroke.
     *
     * @param list<ListItem> $items
     */
    public function withItems(array $items): self
    {
        if ($items === $this->items) {
            return $this;
        }

        $keys = array_map(static fn (ListItem $item): string => $item->key(), $items);

        return $this->cloneWith(
            items: $items,
            rowIndex: RowIndex::of(count($items)),
            selected: Places::placeOf($this->selectedItem()?->key(), $keys, $this->selected ?? 0),
            cursor: Places::cursorAfter(($this->items[$this->cursor] ?? null)?->key(), $keys, $this->cursor),
        );
    }

    /**
     * @return list<ListItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function cursorItem(): ?ListItem
    {
        return $this->items[$this->cursor] ?? null;
    }

    public function withCursorItem(ListItem $item): self
    {
        if (!isset($this->items[$this->cursor])) {
            return $this;
        }

        $items = $this->items;
        $items[$this->cursor] = $item;

        return $this->withItems(array_values($items))->withCursor($this->cursor);
    }

    public function withAddedItem(ListItem $item): self
    {
        return $this->withItems([...$this->items, $item])->withCursor(\count($this->items));
    }

    public function withoutCursorItem(): self
    {
        if (!isset($this->items[$this->cursor])) {
            return $this;
        }

        $items = $this->items;
        array_splice($items, $this->cursor, 1);

        return $this->withItems($items);
    }

    public function withSelectedIndex(?int $selected): self
    {
        return $this->cloneWith(selected: $selected !== null && isset($this->items[$selected]) ? $selected : null);
    }

    /**
     * @param list<string> $strings
     */
    public static function fromStrings(array $strings): self
    {
        $items = array_map(fn (string $text) => new SimpleListItem($text), $strings);

        return self::new($items);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * Keep externally moved cursors visible too, such as when a search field handles navigation.
     */
    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: max(0, min($cursor, count($this->items) - 1)), followCursor: true);
    }

    public function selectedIndex(): ?int
    {
        return $this->selected;
    }

    public function selectedItem(): ?ListItem
    {
        return $this->selected === null ? null : $this->items[$this->selected] ?? null;
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        $item = match (true) {
            $message instanceof MouseMsg && $message->isClick() => $this->itemAt($message, $area),
            $message instanceof KeyMsg && $this->effectiveKeybindings()->matches($message, Actions::SELECT) => $this->cursorItem(),
            default => null,
        };

        return $item === null ? null : new Chosen($item, $message);
    }

    public function scrollOffset(Rect $area): int
    {
        return $this->followCursor && $this->items !== []
            ? $this->cursorVisibleOffset($area)
            : $this->clampedScrollOffset($area);
    }

    /**
     * Scroll explicitly; the view stops following the cursor until it moves again.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: $scrollOffset, followCursor: false);
    }

    /**
     * Fewer where the view is too short.
     */
    public function withScrollPadding(int $rows): self
    {
        return $this->cloneWith(scrollPadding: max(0, $rows));
    }

    public function itemCount(): int
    {
        return count($this->items);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $count = count($this->items);

        return match (true) {
            $count === 0 => new UpdateResult($this),
            $message instanceof KeyMsg => $this->handleKey($message, $area),
            $message instanceof MouseMsg => $this->handleMouse($message, $area),
            default => new UpdateResult($this),
        };
    }

    /**
     * @internal item heights vary with content, so hit-testing cannot assume a fixed row height
     */
    public function indexAt(MouseMsg $message, Rect $area): ?int
    {
        if (!$message->isIn($area)) {
            return null;
        }

        return $this->itemIndexAtY(
            $message->y - $area->y,
            $this->scrollOffset($area),
            $this->itemWidth($area),
        );
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height <= 0 || count($this->items) === 0) {
            return;
        }

        $theme = $context->theme;
        $displayOffset = $this->scrollOffset($area);

        // Decide whether a scrollbar is needed, then measure at the narrower width; narrowing can only increase height.
        $itemWidth = $this->itemWidth($area);
        $extents = $this->extentsAt($itemWidth);
        $totalRows = $extents->totalHeight();
        $hasScrollbar = Scrollbar::needsScrollbar($totalRows, $area->height);

        // Skip a partially visible item so the viewport starts with a whole item.
        $first = $extents->firstRowAtOrAfter($displayOffset);
        $renderY = $area->y;

        $frame->pushClip($area);
        try {
            for ($index = $first; $index < count($this->items); ++$index) {
                if ($renderY >= $area->bottom()) {
                    break;
                }

                $this->renderItem(
                    $frame,
                    $area->x,
                    $renderY,
                    $itemWidth,
                    $this->items[$index],
                    $index === $this->cursor,
                    $index === $this->selected,
                    $context,
                    $this->drawsFocused($context),
                );

                $renderY += $extents->heightOf($index);
            }
        } finally {
            $frame->popClip();
        }

        if ($hasScrollbar) {
            Scrollbar::renderFor($frame, $area, $displayOffset, $totalRows, $theme);
        }
    }

    public function measureHeight(int $width): int
    {
        return $width < 1 ? 0 : $this->extentsAt($width)->totalHeight();
    }

    public function cursorLine(int $width): int
    {
        return $width < 1 || $this->items === [] ? 0 : $this->extentsAt($width)->startOf($this->cursor);
    }

    private function itemAt(MouseMsg $message, Rect $area): ?ListItem
    {
        $index = $this->indexAt($message, $area);

        return $index === null ? null : $this->items[$index] ?? null;
    }

    private function renderItem(Frame $frame, int $x, int $y, int $width, ListItem $item, bool $isCursor, bool $isSelected, RenderContext $context, bool $focused): void
    {
        $theme = $context->theme;
        $style = match (true) {
            $isCursor && $focused => $theme->cursorStyle,
            $isCursor => self::unfocusedCursorStyle($theme),
            $isSelected => $theme->accent,
            $focused => $theme->base,
            default => $theme->dim,
        };
        $filled = $isCursor && $style->background !== null;

        $highlighted = $context->highlighted($style, $filled, $item->pulsing(), $item->key());
        if ($highlighted !== null) {
            $style = $highlighted;
            $filled = true;
        }

        $rows = ListItemLayout::rows($item, $width);

        if ($filled) {
            $frame->fillRect($x, $y, $width, count($rows), $style->foregroundValue(), $style->backgroundValue(), $style->attributes);
        }

        $descriptionStyle = $this->descriptionStyle($theme, $style, $filled);

        foreach ($rows as $offset => $row) {
            $frame->putStyled(
                $x + $row['indent'],
                $y + $offset,
                $row['text'],
                $row['isDescription'] ? $descriptionStyle : $style,
            );
        }
    }

    private function itemWidth(Rect $area): int
    {
        $full = $area->width;

        return $this->overflowsAt($full, $area->height)
            ? max(1, $full - self::SCROLLBAR_GUTTER)
            : $full;
    }

    private function overflowsAt(int $width, int $height): bool
    {
        $items = $this->items;

        return $this->rowIndex->exceeds($width, $height, static fn (int $index): int => $items[$index]->measureHeight($width));
    }

    private function extents(Rect $area): RowExtents
    {
        return $this->extentsAt($this->itemWidth($area));
    }

    private function extentsAt(int $width): RowExtents
    {
        $items = $this->items;

        return $this->rowIndex->at($width, static fn (int $index): int => $items[$index]->measureHeight($width));
    }

    /**
     * Derive description contrast from the highlighted row, whose background may be light or dark.
     */
    private function descriptionStyle(Theme $theme, Style $rowStyle, bool $filled): Style
    {
        if (!$filled) {
            return $theme->dim;
        }

        return new Style(
            foreground: $rowStyle->foreground ?? $theme->base->foreground,
            background: $rowStyle->background,
        );
    }

    private static function unfocusedCursorStyle(Theme $theme): Style
    {
        $selected = $theme->selectedStyle;

        return $theme->accent
            ->withBackground($selected->background ?? Color::ansi(AnsiColor::Black))
            ->bold();
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message, Rect $area): UpdateResult
    {
        $count = count($this->items);
        $bindings = $this->effectiveKeybindings();

        $updated = match (true) {
            $bindings->matches($message, Actions::CURSOR_UP) => $this->cursor > 0 ? $this->cloneWith(cursor: $this->cursor - 1) : null,
            $bindings->matches($message, Actions::CURSOR_DOWN) => $this->cursor < $count - 1 ? $this->cloneWith(cursor: $this->cursor + 1) : null,
            $bindings->matches($message, Actions::HOME) => $this->cursor > 0 ? $this->cloneWith(cursor: 0) : null,
            $bindings->matches($message, Actions::END) => $this->cursor < $count - 1 ? $this->cloneWith(cursor: $count - 1) : null,
            $bindings->matches($message, Actions::PAGE_DOWN) => $this->cursor < $count - 1 ? $this->pagedBy(1, $area) : null,
            $bindings->matches($message, Actions::PAGE_UP) => $this->cursor > 0 ? $this->pagedBy(-1, $area) : null,
            $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->cursor),
            default => null,
        };

        if ($updated !== null) {
            $scrolled = $updated->ensureCursorVisible($area);

            return new UpdateResult($scrolled);
        }

        return new UpdateResult($this);
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $displayOffset = $this->scrollOffset($area);

        $itemWidth = $this->itemWidth($area);

        if ($message->isClick() && $message->isIn($area)) {
            $clickedIndex = $this->itemIndexAtY($message->y - $area->y, $displayOffset, $itemWidth);

            if ($clickedIndex !== null) {
                return new UpdateResult($this->cloneWith(cursor: $clickedIndex, selected: $clickedIndex, followCursor: true));
            }
        }

        if ($message->isIn($area)) {
            if ($message->action === MouseAction::WheelDown) {
                $scrolled = ScrollWindow::clamped(
                    $displayOffset + $message->wheelSteps(),
                    $this->extents($area)->maxOffset($area->height),
                );

                return new UpdateResult($this->cloneWith(scrollOffset: $scrolled, followCursor: false));
            }

            if ($message->action === MouseAction::WheelUp) {
                return new UpdateResult($this->cloneWith(scrollOffset: max(0, $displayOffset - $message->wheelSteps()), followCursor: false));
            }
        }

        return new UpdateResult($this);
    }

    private function itemIndexAtY(int $localY, int $displayOffset, int $itemWidth): ?int
    {
        $extents = $this->extentsAt($itemWidth);
        // Hit-test from the first drawn item's actual start, not the raw scroll offset.
        $first = $extents->firstRowAtOrAfter($displayOffset);

        return $extents->rowAtLine($extents->startOf($first) + $localY);
    }

    /**
     * Place the landing item at the destination edge so repeated page moves cover a full viewport.
     */
    private function pagedBy(int $direction, Rect $area): self
    {
        $extents = $this->extents($area);
        $cursor = $this->itemAPageAway($direction, $area);
        $anchor = $direction > 0
            ? $extents->startOf($cursor)
            : $extents->startOf($cursor) + $extents->heightOf($cursor) - $area->height;

        return $this->cloneWith(
            cursor: $cursor,
            scrollOffset: ScrollWindow::clamped($anchor, $extents->maxOffset($area->height)),
        );
    }

    /**
     * Page from the viewport edge so items between the cursor and that edge are not skipped.
     */
    private function itemAPageAway(int $direction, Rect $area): int
    {
        $extents = $this->extents($area);
        $last = max(0, $extents->rowCount() - 1);
        $top = $this->scrollOffset($area);

        $line = $direction > 0
            ? $top + max(1, $area->height)
            : $top - 1;

        if ($line < 0) {
            return 0;
        }

        return $extents->rowAtLine(min($line, max(0, $extents->totalHeight() - 1))) ?? $last;
    }

    private function ensureCursorVisible(Rect $area): self
    {
        return $this->cloneWith(scrollOffset: $this->cursorVisibleOffset($area), followCursor: true);
    }

    private function clampedScrollOffset(Rect $area): int
    {
        return ScrollWindow::clamped($this->scrollOffset, $this->extents($area)->maxOffset($area->height));
    }

    private function cursorVisibleOffset(Rect $area): int
    {
        $cursor = min(max(0, $this->cursor), count($this->items) - 1);

        return $this->extents($area)->offsetShowing($cursor, $area->height, $this->scrollOffset, $this->scrollPadding);
    }
}
