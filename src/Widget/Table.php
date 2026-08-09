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
use Elmphant\Tui\Layout\KeyedCache;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Theme;

/**
 * A table the user navigates: cursor, selection, mouse, and a scrolled viewport.
 *
 * Columns are sized by their Constraint to fill the width given, so cells
 * truncate or wrap rather than widening the table. Only the rows in view are
 * drawn, which is what makes it usable for unbounded row counts. For a bordered
 * table that just prints a small data set, see StaticTable.
 *
 * Rows given as plain cell text become Row values on the way in, so there is
 * one key path and one row renderer whichever shape the caller used.
 */
final readonly class Table implements Focusable, KeybindingsAware, MeasureHeight
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private const WHEEL_SCROLL_STEP = 1;

    /**
     * Row heights for the current rows, shared with every clone that keeps
     * them, so moving the cursor does not re-measure the table.
     *
     * @var KeyedCache<TableLayout>
     */
    private KeyedCache $layoutMemo;

    /**
     * @param list<Column>            $columns
     * @param list<Row>               $rows
     * @param KeyedCache<TableLayout> $layoutMemo
     */
    private function __construct(
        private array $columns = [],
        private array $rows = [],
        private bool $rowsAreTyped = false,
        private int $cursor = 0,
        private int $selected = -1,
        private int $scrollOffset = 0,
        private bool $followCursor = true,
        private bool $focused = false,
        private bool $header = true,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        ?KeyedCache $layoutMemo = null,
    ) {
        $this->layoutMemo = $layoutMemo ?? self::newLayoutMemo();
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::rowNavigation();
    }

    /**
     * @param list<Column>       $columns
     * @param list<list<string>> $rows
     */
    public static function new(array $columns, array $rows): self
    {
        return new self(
            columns: $columns,
            rows: self::asTypedRows($rows),
        );
    }

    /**
     * @param list<Column> $columns
     * @param list<Row>    $rows
     */
    public static function fromRows(array $columns, array $rows): self
    {
        $initialCursor = 0;
        foreach ($rows as $index => $row) {
            if ($row->selectable) {
                $initialCursor = $index;
                break;
            }
        }

        return new self(
            columns: $columns,
            rows: $rows,
            rowsAreTyped: true,
            cursor: $initialCursor,
        );
    }

    /**
     * @param list<Column> $columns
     */
    public function withColumns(array $columns): self
    {
        return $this->cloneWith(columns: $columns, layoutMemo: self::newLayoutMemo());
    }

    public function withHeader(bool $visible): self
    {
        return $this->cloneWith(header: $visible);
    }

    /**
     * @param list<list<string>> $rows
     */
    public function withRows(array $rows): self
    {
        return $this->cloneWith(rows: self::asTypedRows($rows), rowsAreTyped: false, layoutMemo: self::newLayoutMemo());
    }

    /**
     * @param list<Row> $rows
     */
    public function withTypedRows(array $rows): self
    {
        return $this->cloneWith(rows: $rows, rowsAreTyped: true, layoutMemo: self::newLayoutMemo());
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    /**
     * Scroll explicitly; the view stops following the cursor until it moves again.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: $scrollOffset, followCursor: false);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * Move the cursor and keep it on screen at the next render, even when it is
     * driven from outside the focused-key path — a search box may hold focus
     * while the arrow keys navigate this table.
     */
    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor, followCursor: true);
    }

    public function selected(): int
    {
        return $this->selected;
    }

    /**
     * Cell text of the selected row.
     *
     * @return list<string>|null
     */
    public function selectedRow(): ?array
    {
        $row = $this->rows[$this->selected] ?? null;

        return $row === null ? null : self::textOf($row);
    }

    /**
     * The selected row itself, for tables built from typed rows.
     */
    public function selectedTypedRow(): ?Row
    {
        if (!$this->rowsAreTyped) {
            return null;
        }

        return $this->rows[$this->selected] ?? null;
    }

    public function scrollOffset(): int
    {
        return $this->scrollOffset;
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
        $rowCount = $this->rowCount();

        return match (true) {
            $message instanceof KeyMsg && $this->focused && $rowCount > 0 => $this->handleKey($message, $area),
            $message instanceof MouseMsg => $this->handleMouse($message, $area, $rowCount),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height <= 0) {
            return;
        }

        $theme = $context->theme;
        $rowCount = $this->rowCount();
        $visibleRows = $area->height - $this->headerHeight();
        $showScrollbar = Scrollbar::needsScrollbar($rowCount, $visibleRows);

        $columnWidths = $this->columnWidths($showScrollbar ? $area->width - 1 : $area->width);

        $frame->pushClip($area);
        try {
            if ($this->header) {
                $this->renderHeader($frame, $area, $columnWidths, $theme);
            }
            $this->renderRows($frame, $area, $columnWidths, $theme);

            if ($showScrollbar) {
                Scrollbar::render(
                    $frame,
                    $area->x + $area->width - 1,
                    $area->y + $this->headerHeight(),
                    $visibleRows,
                    $this->displayScrollOffset($area),
                    $rowCount,
                    $visibleRows,
                    $theme->accent->foregroundValue(),
                    $theme->dim->foregroundValue(),
                    $theme->base->backgroundValue(),
                );
            }
        } finally {
            $frame->popClip();
        }
    }

    public function measureHeight(int $width): int
    {
        return $this->headerHeight() + $this->layoutFor($this->columnWidths($width))->totalHeight;
    }

    /**
     * @return list<int>
     */
    public function columnWidths(int $totalWidth): array
    {
        $columnCount = count($this->columns);
        if ($columnCount === 0) {
            return [];
        }

        $constraints = [];
        foreach ($this->columns as $column) {
            $constraints[] = $column->constraint();
        }

        return Constraint::allocate($constraints, $totalWidth);
    }

    /**
     * @return KeyedCache<TableLayout>
     */
    private static function newLayoutMemo(): KeyedCache
    {
        /** @var KeyedCache<TableLayout> $cache */
        $cache = new KeyedCache();

        return $cache;
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return list<Row>
     */
    private static function asTypedRows(array $rows): array
    {
        $typedRows = [];
        foreach ($rows as $cells) {
            $typedRows[] = new Row(cells: $cells);
        }

        return $typedRows;
    }

    private function headerHeight(): int
    {
        return $this->header ? 1 : 0;
    }

    private function rowCount(): int
    {
        return count($this->rows);
    }

    private function isRowSelectable(int $index): bool
    {
        return $this->rows[$index]->selectable ?? true;
    }

    private function findNextSelectable(int $from, int $direction): ?int
    {
        $rowCount = $this->rowCount();
        $position = $from + $direction;

        while ($position >= 0 && $position < $rowCount) {
            if ($this->isRowSelectable($position)) {
                return $position;
            }
            $position += $direction;
        }

        return null;
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleKey(KeyMsg $message, Rect $area): UpdateResult
    {
        $rowCount = $this->rowCount();
        $visibleRows = max(1, $area->height - $this->headerHeight());
        $bindings = $this->effectiveKeybindings();

        $updated = match (true) {
            $bindings->matches($message, Actions::FOCUS_PREV) => $this->withCursorAt($this->findNextSelectable($this->cursor, -1)),
            $bindings->matches($message, Actions::FOCUS_NEXT) => $this->withCursorAt($this->findNextSelectable($this->cursor, 1)),
            $bindings->matches($message, Actions::HOME) => $this->withCursorAt($this->findNextSelectable(-1, 1)),
            $bindings->matches($message, Actions::END) => $this->withCursorAt($this->findNextSelectable($rowCount, -1)),
            $bindings->matches($message, Actions::PAGE_DOWN) => $this->withCursorAt($this->rowAPageAway(1, $visibleRows, $area)),
            $bindings->matches($message, Actions::PAGE_UP) => $this->withCursorAt($this->rowAPageAway(-1, $visibleRows, $area)),
            $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->selected === $this->cursor ? -1 : $this->cursor),
            default => null,
        };

        if ($updated !== null) {
            return new UpdateResult($updated->ensureCursorVisible($area));
        }

        return new UpdateResult($this);
    }

    private function withCursorAt(?int $row): ?self
    {
        if ($row === null || $row === $this->cursor) {
            return null;
        }

        return $this->cloneWith(cursor: $row);
    }

    /**
     * The row a page away from the cursor, stepped back towards the cursor
     * until it is one the cursor may rest on.
     */
    private function rowAPageAway(int $direction, int $visibleRows, Rect $area): int
    {
        $layout = $this->layoutFor($this->columnWidthsFor($area));

        $target = $direction > 0
            ? $layout->rowAtLine($this->cursor, $visibleRows) ?? max(0, $this->rowCount() - 1)
            : $this->rowAPageAbove($layout, $visibleRows);

        while ($target !== $this->cursor && !$this->isRowSelectable($target)) {
            $target -= $direction;
        }

        return $target;
    }

    private function rowAPageAbove(TableLayout $layout, int $visibleRows): int
    {
        $lines = 0;
        for ($row = $this->cursor - 1; $row >= 0; --$row) {
            $lines += $layout->heightOf($row);

            if ($lines >= $visibleRows) {
                return $row;
            }
        }

        return 0;
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleMouse(MouseMsg $message, Rect $area, int $rowCount): UpdateResult
    {
        $displayOffset = $this->displayScrollOffset($area);

        if ($message->isClick() && $message->inRect($area)) {
            $localY = $message->y - $area->y - $this->headerHeight();
            $rowIndex = $localY >= 0
                ? $this->layoutFor($this->columnWidthsFor($area))->rowAtLine($displayOffset, $localY)
                : null;

            if ($rowIndex !== null && $rowIndex < $rowCount && $this->isRowSelectable($rowIndex)) {
                return new UpdateResult($this->cloneWith(cursor: $rowIndex, selected: $rowIndex, followCursor: true));
            }
        }

        if ($message->inRect($area)) {
            if ($message->action === MouseAction::WheelDown) {
                $maxOffset = $this->layoutFor($this->columnWidthsFor($area))->maxOffset(max(1, $area->height - $this->headerHeight()));

                return new UpdateResult($this->cloneWith(scrollOffset: min($maxOffset, $displayOffset + $message->wheelSteps(self::WHEEL_SCROLL_STEP)), followCursor: false));
            }

            if ($message->action === MouseAction::WheelUp) {
                return new UpdateResult($this->cloneWith(scrollOffset: max(0, $displayOffset - $message->wheelSteps(self::WHEEL_SCROLL_STEP)), followCursor: false));
            }
        }

        return new UpdateResult($this);
    }

    /**
     * @param list<int> $columnWidths
     */
    private function renderHeader(Frame $frame, Rect $area, array $columnWidths, Theme $theme): void
    {
        $headerStyle = $theme->headerStyle->underline();
        $cellX = $area->x;

        foreach ($this->columns as $index => $column) {
            $width = $columnWidths[$index] ?? 0;
            if ($width <= 0) {
                continue;
            }

            $title = CharWidth::truncateToWidth($column->title(), $width);
            $frame->putStyled($cellX, $area->y, $title, $headerStyle);
            $cellX += $width;
        }
    }

    /**
     * @param list<int> $columnWidths
     */
    private function renderRows(Frame $frame, Rect $area, array $columnWidths, Theme $theme): void
    {
        $layout = $this->layoutFor($columnWidths);
        $totalWidth = array_sum($columnWidths);
        $renderY = $area->y + $this->headerHeight();

        // A scroll container sizes its child to the child's whole content and
        // clips the overflow, so the area is not the viewport — the clip is.
        // Reading rows past it would draw thousands of invisible lines.
        $lastVisibleY = $frame->clip()->bottom();

        for ($rowIndex = $this->displayScrollOffset($area); $rowIndex < $this->rowCount(); ++$rowIndex) {
            if ($renderY >= $lastVisibleY) {
                break;
            }

            $height = $layout->heightOf($rowIndex);
            $this->renderRow($frame, $area->x, $renderY, $rowIndex, $this->rows[$rowIndex], $columnWidths, $totalWidth, $theme, $height);

            $renderY += $height;
        }
    }

    /**
     * @param list<int> $columnWidths
     */
    private function renderRow(Frame $frame, int $startX, int $renderY, int $rowIndex, Row $row, array $columnWidths, int $totalWidth, Theme $theme, int $height): void
    {
        if ($row->spansAllColumns) {
            foreach (TableLayout::spanningLines($columnWidths, $row) as $lineIndex => $line) {
                $frame->putStyled($startX, $renderY + $lineIndex, $line, $theme->headerStyle);
            }

            return;
        }

        $isCursor = $rowIndex === $this->cursor;
        $isSelected = $rowIndex === $this->selected;
        $reverse = ($isCursor && $this->focused) || $isSelected;

        $defaultStyle = match (true) {
            $this->focused => $theme->base,
            default => $theme->dim,
        };

        if ($reverse) {
            $defaultStyle = $defaultStyle->reverse();
            $defaultStyle->apply($frame, new Rect($startX, $renderY, $totalWidth, $height));
        }

        $rowStyle = $row->style ?? $defaultStyle;
        if ($reverse && $row->style !== null) {
            $rowStyle = $rowStyle->reverse();
        }

        $rowLines = TableLayout::linesOfRow($columnWidths, $row);
        $cellX = $startX;

        foreach (array_keys($this->columns) as $columnIndex) {
            $width = $columnWidths[$columnIndex] ?? 0;
            if ($width <= 0) {
                continue;
            }

            $cell = $row->cells[$columnIndex] ?? '';
            $cellStyle = $cell instanceof Span
                ? $cell->withResolvedToken($theme)->style
                : null;

            $cellRenderStyle = match (true) {
                $cellStyle === null => $rowStyle,
                $reverse => $cellStyle->reverse(),
                default => $cellStyle,
            };

            for ($line = 0; $line < $height; ++$line) {
                $frame->putStyled($cellX, $renderY + $line, $rowLines[$columnIndex][$line] ?? '', $cellRenderStyle);
            }

            $cellX += $width;
        }
    }

    /**
     * @param list<int> $columnWidths
     */
    private function layoutFor(array $columnWidths): TableLayout
    {
        return $this->layoutMemo->at(
            implode(',', $columnWidths),
            fn (): TableLayout => TableLayout::forRows($columnWidths, $this->rows),
        );
    }

    /**
     * @return list<int>
     */
    private function columnWidthsFor(Rect $area): array
    {
        $showScrollbar = Scrollbar::needsScrollbar($this->rowCount(), max(1, $area->height - $this->headerHeight()));

        return $this->columnWidths($showScrollbar ? $area->width - 1 : $area->width);
    }

    /**
     * @return list<string>
     */
    private static function textOf(Row $row): array
    {
        $cells = [];
        foreach ($row->cells as $cell) {
            $cells[] = $cell instanceof Span ? $cell->content : $cell;
        }

        return $cells;
    }

    private function ensureCursorVisible(Rect $area): self
    {
        return $this->cloneWith(scrollOffset: $this->cursorVisibleOffset($area));
    }

    /**
     * The offset actually drawn: it follows the cursor unless the view was
     * scrolled away from it, so a cursor moved from outside update() is still
     * on screen at the next paint.
     */
    private function displayScrollOffset(Rect $area): int
    {
        return $this->followCursor
            ? $this->cursorVisibleOffset($area)
            : $this->scrollOffset;
    }

    private function cursorVisibleOffset(Rect $area): int
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->columnWidthsFor($area));
        $offset = min($this->scrollOffset, $this->cursor);

        while ($offset < $this->cursor && $layout->rowAtLine($offset, $viewportHeight - 1) !== null
            && $layout->rowAtLine($offset, $viewportHeight - 1) < $this->cursor
        ) {
            ++$offset;
        }

        return $offset;
    }
}
