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
use Elmphant\Tui\Layout\RowExtents;
use Elmphant\Tui\Layout\RowIndex;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;

final readonly class ListView implements Focusable, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private const WHEEL_SCROLL_STEP = 1;
    /**
     * Scrollbar column plus one blank column between it and the text.
     */
    private const SCROLLBAR_GUTTER = 2;

    /**
     * Measurements for $items, shared with every clone that keeps them. Passed
     * through the constructor because cloneWith() reconstructs from properties;
     * withItems() is the only thing that replaces it.
     */
    private RowIndex $rowIndex;

    /**
     * @param list<ListItem> $items
     */
    public function __construct(
        private array $items = [],
        private int $cursor = 0,
        private int $selected = -1,
        private int $scrollOffset = 0,
        private bool $focused = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $followCursor = true,
        ?RowIndex $rowIndex = null,
    ) {
        $this->rowIndex = $rowIndex ?? RowIndex::of(count($items));
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::rowNavigation();
    }

    /**
     * @param list<ListItem> $items
     */
    public static function new(array $items): self
    {
        return new self(
            items: $items,
        );
    }

    /**
     * @param list<ListItem> $items
     */
    public function withItems(array $items): self
    {
        if ($items === $this->items) {
            return $this;
        }

        return $this->cloneWith(items: $items, rowIndex: RowIndex::of(count($items)));
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
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
     * Move the cursor and keep it visible on the next render, even when the
     * cursor is driven from outside the focused-key path (e.g. a search box
     * holds focus while arrow keys navigate this list).
     */
    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor, followCursor: true);
    }

    public function selected(): int
    {
        return $this->selected;
    }

    public function selectedItem(): ?ListItem
    {
        return $this->items[$this->selected] ?? null;
    }

    public function scrollOffset(): int
    {
        return $this->scrollOffset;
    }

    /**
     * Scroll explicitly; the view stops following the cursor until it moves again.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: $scrollOffset, followCursor: false);
    }

    public function itemCount(): int
    {
        return count($this->items);
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
        return Constraint::flex();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $count = count($this->items);

        return match (true) {
            $count === 0 => new UpdateResult($this),
            $message instanceof KeyMsg && $this->focused => $this->handleKey($message, $area),
            $message instanceof MouseMsg => $this->handleMouse($message, $area),
            default => new UpdateResult($this),
        };
    }

    /**
     * The item a click lands on, or null past the last one.
     *
     * Items are as tall as their content, so a caller cannot divide by a row
     * height: it has to ask.
     */
    public function indexAt(MouseMsg $message, Rect $area): ?int
    {
        if (!$message->inRect($area)) {
            return null;
        }

        return $this->itemIndexAtY(
            $message->y - $area->y,
            $this->displayScrollOffset($area),
            $this->itemWidth($area),
        );
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height <= 0 || count($this->items) === 0) {
            return;
        }

        $theme = $context->theme;
        $displayOffset = $this->displayScrollOffset($area);

        // Reserve the scrollbar column plus a gap, so item text never collides
        // with the bar. Narrowing the items can only make them taller, so one
        // pass to decide the gutter and one to measure at that width settles.
        $itemWidth = $this->itemWidth($area);
        $extents = $this->extentsAt($itemWidth);
        $totalRows = $extents->totalHeight();
        $hasScrollbar = Scrollbar::needsScrollbar($totalRows, $area->height);

        // Items are atomic: an offset landing inside one skips past it rather
        // than clipping it, and the next item sits at the top of the viewport.
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
                    $theme,
                );

                $renderY += $extents->heightOf($index);
            }
        } finally {
            $frame->popClip();
        }

        if ($hasScrollbar) {
            Scrollbar::render(
                $frame,
                $area->x + $area->width - 1,
                $area->y,
                $area->height,
                $displayOffset,
                $totalRows,
                $area->height,
                $theme->accent->foregroundValue(),
                $theme->dim->foregroundValue(),
                $theme->base->backgroundValue(),
            );
        }
    }

    private function renderItem(Frame $frame, int $x, int $y, int $width, ListItem $item, bool $isCursor, bool $isSelected, Theme $theme): void
    {
        $style = match (true) {
            $isCursor && $this->focused => $theme->cursorStyle,
            $isCursor => self::unfocusedCursorStyle($theme),
            $isSelected => $theme->accent,
            $this->focused => $theme->base,
            default => $theme->dim,
        };

        $rows = ListItemLayout::rows($item, $width);

        if ($isCursor && $style->background !== null) {
            $frame->fillRect($x, $y, $width, count($rows), $style->foregroundValue(), $style->backgroundValue(), $style->attributes);
        }

        $descriptionStyle = $this->descriptionStyle($theme, $style, $isCursor);

        foreach ($rows as $offset => $row) {
            $frame->putStyled(
                $x + $row['indent'],
                $y + $offset,
                $row['text'],
                $row['isDescription'] ? $descriptionStyle : $style,
            );
        }
    }

    /**
     * The width available to items, once any scrollbar gutter is reserved.
     */
    private function itemWidth(Rect $area): int
    {
        $full = $area->width;

        return Scrollbar::needsScrollbar($this->extentsAt($full)->totalHeight(), $area->height)
            ? max(1, $full - self::SCROLLBAR_GUTTER)
            : $full;
    }

    private function extents(Rect $area): RowExtents
    {
        return $this->extentsAt($this->itemWidth($area));
    }

    /**
     * Item heights at $width, measured once and reused by every clone that
     * keeps the same items.
     */
    private function extentsAt(int $width): RowExtents
    {
        $items = $this->items;

        return $this->rowIndex->at($width, static fn (int $index): int => $items[$index]->height($width));
    }

    /**
     * A highlight background may be light or dark, so a description on it has to
     * follow the row's own pair rather than any fixed foreground.
     */
    private function descriptionStyle(Theme $theme, Style $rowStyle, bool $isCursor): Style
    {
        if (!$isCursor) {
            return $theme->dim;
        }

        return new Style(
            foreground: $rowStyle->foreground ?? $theme->base->foreground,
            background: $rowStyle->background,
        );
    }

    /**
     * Selection shown while another widget holds focus: accented, bold text on
     * the selection background, so it never blends into the surrounding rows.
     */
    private static function unfocusedCursorStyle(Theme $theme): Style
    {
        $selected = $theme->selectedStyle;

        return $theme->accent
            ->background($selected->background ?? Color::ansi(0))
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
            $bindings->matches($message, Actions::FOCUS_PREV) => $this->cursor > 0 ? $this->cloneWith(cursor: $this->cursor - 1) : null,
            $bindings->matches($message, Actions::FOCUS_NEXT) => $this->cursor < $count - 1 ? $this->cloneWith(cursor: $this->cursor + 1) : null,
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
        $displayOffset = $this->displayScrollOffset($area);

        $itemWidth = $this->itemWidth($area);

        if ($message->isClick() && $message->inRect($area)) {
            $clickedIndex = $this->itemIndexAtY($message->y - $area->y, $displayOffset, $itemWidth);

            if ($clickedIndex !== null) {
                return new UpdateResult($this->cloneWith(cursor: $clickedIndex, selected: $clickedIndex, followCursor: true));
            }
        }

        if ($message->inRect($area)) {
            if ($message->action === MouseAction::WheelDown) {
                $maxOffset = $this->totalItemRows($itemWidth) - $area->height;

                return new UpdateResult($this->cloneWith(scrollOffset: min(max(0, $maxOffset), $displayOffset + $message->wheelSteps(self::WHEEL_SCROLL_STEP)), followCursor: false));
            }

            if ($message->action === MouseAction::WheelUp) {
                return new UpdateResult($this->cloneWith(scrollOffset: max(0, $displayOffset - $message->wheelSteps(self::WHEEL_SCROLL_STEP)), followCursor: false));
            }
        }

        return new UpdateResult($this);
    }

    private function itemIndexAtY(int $localY, int $displayOffset, int $itemWidth): ?int
    {
        $extents = $this->extentsAt($itemWidth);
        // Measured from where the first drawn item actually starts, so a click
        // resolves against the view rather than the raw offset.
        $first = $extents->firstRowAtOrAfter($displayOffset);

        return $extents->rowAtLine($extents->startOf($first) + $localY);
    }

    /**
     * The landing item takes the edge the reader travels towards, so the fold it
     * came from is a whole viewport behind and the next page moves as far again.
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
            scrollOffset: min(max(0, $anchor), $extents->maxOffset($area->height)),
        );
    }

    /**
     * Measured from the viewport, not from the cursor, so paging never passes
     * over the items between the cursor and the fold without drawing them.
     */
    private function itemAPageAway(int $direction, Rect $area): int
    {
        $extents = $this->extents($area);
        $last = max(0, $extents->rowCount() - 1);
        $top = $this->displayScrollOffset($area);

        $line = $direction > 0
            ? $top + max(1, $area->height)
            : $top - 1;

        if ($line < 0) {
            return 0;
        }

        return $extents->rowAtLine(min($line, max(0, $extents->totalHeight() - 1))) ?? $last;
    }

    private function totalItemRows(int $itemWidth): int
    {
        return $this->extentsAt($itemWidth)->totalHeight();
    }

    private function ensureCursorVisible(Rect $area): self
    {
        return $this->cloneWith(scrollOffset: $this->cursorVisibleOffset($area));
    }

    /**
     * The scroll offset actually shown: follows the cursor unless the user scrolled away.
     */
    private function displayScrollOffset(Rect $area): int
    {
        return $this->followCursor && $this->items !== []
            ? $this->cursorVisibleOffset($area)
            : $this->clampedScrollOffset($area);
    }

    /**
     * An offset past either end would draw blank rows; a caller cannot overshoot
     * into them.
     */
    private function clampedScrollOffset(Rect $area): int
    {
        return ScrollWindow::clamped($this->scrollOffset, $this->extents($area)->maxOffset($area->height));
    }

    private function cursorVisibleOffset(Rect $area): int
    {
        $cursor = min(max(0, $this->cursor), count($this->items) - 1);

        return $this->extents($area)->offsetShowing($cursor, $area->height, $this->scrollOffset);
    }
}
