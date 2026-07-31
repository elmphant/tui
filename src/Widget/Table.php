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
     * @param list<list<string>>      $rows
     * @param list<Row>               $typedRows
     * @param KeyedCache<TableLayout> $layoutMemo
     */
    private function __construct(
        private array $columns = [],
        private array $rows = [],
        private array $typedRows = [],
        private int $cursor = 0,
        private int $selected = -1,
        private int $scrollOffset = 0,
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
            rows: $rows,
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
            typedRows: $rows,
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
        return $this->cloneWith(rows: $rows, typedRows: [], layoutMemo: self::newLayoutMemo());
    }

    /**
     * @param list<Row> $rows
     */
    public function withTypedRows(array $rows): self
    {
        return $this->cloneWith(rows: [], typedRows: $rows, layoutMemo: self::newLayoutMemo());
    }

    public function withSelected(int $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: $scrollOffset);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: $cursor);
    }

    public function selected(): int
    {
        return $this->selected;
    }

    /**
     * Cell text of the selected row, whichever row representation is in use.
     *
     * @return list<string>|null
     */
    public function selectedRow(): ?array
    {
        if ($this->typedRows === []) {
            return $this->rows[$this->selected] ?? null;
        }

        $typedRow = $this->typedRows[$this->selected] ?? null;

        return $typedRow === null ? null : self::textOf($typedRow);
    }

    /**
     * The selected row itself, for tables built from typed rows.
     */
    public function selectedTypedRow(): ?Row
    {
        return $this->typedRows[$this->selected] ?? null;
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

        $columnWidths = $this->computeColumnWidths($showScrollbar ? $area->width - 1 : $area->width);

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
                    $this->scrollOffset,
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
        return $this->headerHeight() + $this->layoutFor($this->computeColumnWidths($width))->totalHeight;
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

    private function headerHeight(): int
    {
        return $this->header ? 1 : 0;
    }

    private function rowCount(): int
    {
        return $this->typedRows !== [] ? count($this->typedRows) : count($this->rows);
    }

    private function isRowSelectable(int $index): bool
    {
        if ($this->typedRows === []) {
            return true;
        }

        $row = $this->typedRows[$index] ?? null;

        return $row === null ? true : $row->selectable;
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
        $hasTypedRows = $this->typedRows !== [];
        $bindings = $this->effectiveKeybindings();

        if ($hasTypedRows) {
            $updated = match (true) {
                $bindings->matches($message, Actions::FOCUS_PREV) => $this->findNextSelectable($this->cursor, -1) !== null
                    ? $this->cloneWith(cursor: $this->findNextSelectable($this->cursor, -1))
                    : null,
                $bindings->matches($message, Actions::FOCUS_NEXT) => $this->findNextSelectable($this->cursor, 1) !== null
                    ? $this->cloneWith(cursor: $this->findNextSelectable($this->cursor, 1))
                    : null,
                $bindings->matches($message, Actions::HOME) => $this->findNextSelectable(-1, 1) !== null && $this->findNextSelectable(-1, 1) !== $this->cursor
                    ? $this->cloneWith(cursor: $this->findNextSelectable(-1, 1))
                    : null,
                $bindings->matches($message, Actions::END) => $this->findNextSelectable($rowCount, -1) !== null && $this->findNextSelectable($rowCount, -1) !== $this->cursor
                    ? $this->cloneWith(cursor: $this->findNextSelectable($rowCount, -1))
                    : null,
                $bindings->matches($message, Actions::PAGE_DOWN) => $this->handlePageDown($this->rowAPageAway(1, $visibleRows, $area)),
                $bindings->matches($message, Actions::PAGE_UP) => $this->handlePageUp($this->rowAPageAway(-1, $visibleRows, $area)),
                $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->selected === $this->cursor ? -1 : $this->cursor),
                default => null,
            };
        } else {
            $updated = match (true) {
                $bindings->matches($message, Actions::FOCUS_PREV) => $this->cursor > 0 ? $this->cloneWith(cursor: $this->cursor - 1) : null,
                $bindings->matches($message, Actions::FOCUS_NEXT) => $this->cursor < $rowCount - 1 ? $this->cloneWith(cursor: $this->cursor + 1) : null,
                $bindings->matches($message, Actions::HOME) => $this->cursor > 0 ? $this->cloneWith(cursor: 0) : null,
                $bindings->matches($message, Actions::END) => $this->cursor < $rowCount - 1 ? $this->cloneWith(cursor: $rowCount - 1) : null,
                $bindings->matches($message, Actions::PAGE_DOWN) => $this->cursor < $rowCount - 1
                    ? $this->cloneWith(cursor: $this->rowAPageAway(1, $visibleRows, $area))
                    : null,
                $bindings->matches($message, Actions::PAGE_UP) => $this->cursor > 0
                    ? $this->cloneWith(cursor: $this->rowAPageAway(-1, $visibleRows, $area))
                    : null,
                $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->selected === $this->cursor ? -1 : $this->cursor),
                default => null,
            };
        }

        if ($updated !== null) {
            return new UpdateResult($updated->ensureCursorVisible($area));
        }

        return new UpdateResult($this);
    }

    private function rowAPageAway(int $direction, int $visibleRows, Rect $area): int
    {
        $layout = $this->layoutFor($this->columnWidthsFor($area));
        $last = max(0, $this->rowCount() - 1);

        if ($direction > 0) {
            return $layout->rowAtLine($this->cursor, $visibleRows) ?? $last;
        }

        $lines = 0;
        for ($row = $this->cursor - 1; $row >= 0; --$row) {
            $lines += $layout->heightOf($row);

            if ($lines >= $visibleRows) {
                return $row;
            }
        }

        return 0;
    }

    private function handlePageDown(int $target): ?self
    {
        while ($target > $this->cursor && !$this->isRowSelectable($target)) {
            --$target;
        }

        return $target !== $this->cursor ? $this->cloneWith(cursor: $target) : null;
    }

    private function handlePageUp(int $target): ?self
    {
        while ($target < $this->cursor && !$this->isRowSelectable($target)) {
            ++$target;
        }

        return $target !== $this->cursor ? $this->cloneWith(cursor: $target) : null;
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleMouse(MouseMsg $message, Rect $area, int $rowCount): UpdateResult
    {
        if ($message->isClick() && $message->inRect($area)) {
            $localY = $message->y - $area->y - $this->headerHeight();
            $rowIndex = $localY >= 0
                ? $this->layoutFor($this->columnWidthsFor($area))->rowAtLine($this->scrollOffset, $localY)
                : null;

            if ($rowIndex !== null && $rowIndex < $rowCount && $this->isRowSelectable($rowIndex)) {
                return new UpdateResult($this->cloneWith(cursor: $rowIndex, selected: $rowIndex));
            }
        }

        if ($message->inRect($area)) {
            if ($message->action === MouseAction::WheelDown) {
                $maxOffset = $this->layoutFor($this->columnWidthsFor($area))->maxOffset(max(1, $area->height - $this->headerHeight()));

                return new UpdateResult($this->cloneWith(scrollOffset: min($maxOffset, $this->scrollOffset + $message->wheelSteps(self::WHEEL_SCROLL_STEP))));
            }

            if ($message->action === MouseAction::WheelUp) {
                return new UpdateResult($this->cloneWith(scrollOffset: max(0, $this->scrollOffset - $message->wheelSteps(self::WHEEL_SCROLL_STEP))));
            }
        }

        return new UpdateResult($this);
    }

    /**
     * @return list<int>
     */
    private function computeColumnWidths(int $totalWidth): array
    {
        $columnCount = count($this->columns);
        if ($columnCount === 0) {
            return [];
        }

        $widths = array_fill(0, $columnCount, 0);
        $remaining = $totalWidth;
        $totalFlex = 0;

        foreach ($this->columns as $index => $column) {
            $constraint = $column->constraint();
            if ($constraint->min === $constraint->max) {
                $widths[$index] = $constraint->min;
                $remaining -= $constraint->min;
            } else {
                $totalFlex += max(1, $constraint->flex);
            }
        }

        if ($totalFlex > 0 && $remaining > 0) {
            $lastFlexIndex = -1;
            $flexUsed = 0;

            foreach ($this->columns as $index => $column) {
                $constraint = $column->constraint();
                if ($constraint->min !== $constraint->max) {
                    $flex = max(1, $constraint->flex);
                    $size = (int)floor($remaining * $flex / $totalFlex);
                    $widths[$index] = $size;
                    $flexUsed += $size;
                    $lastFlexIndex = $index;
                }
            }

            if ($lastFlexIndex >= 0 && $flexUsed < $remaining) {
                $widths[$lastFlexIndex] += $remaining - $flexUsed;
            }
        }

        return array_values($widths);
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
        $cells = $this->cellText();
        $useTypedRows = $this->typedRows !== [];
        $totalWidth = array_sum($columnWidths);
        $firstRowY = $area->y + $this->headerHeight();
        $renderY = $firstRowY;

        // A scroll container sizes its child to the child's whole content and
        // clips the overflow, so the area is not the viewport — the clip is.
        // Reading rows past it would draw thousands of invisible lines.
        $lastVisibleY = $frame->clip()->bottom();

        for ($rowIndex = $this->scrollOffset; $rowIndex < $this->rowCount(); ++$rowIndex) {
            if ($renderY >= $lastVisibleY) {
                break;
            }

            $height = $layout->heightOf($rowIndex);
            $rowLines = TableLayout::linesOfRow($columnWidths, $cells[$rowIndex] ?? []);

            if ($useTypedRows) {
                $this->renderTypedRow($frame, $area->x, $renderY, $rowIndex, $this->typedRows[$rowIndex], $columnWidths, $totalWidth, $theme, $height, $rowLines);
            } else {
                $this->renderPlainRow($frame, $area->x, $renderY, $rowIndex, $columnWidths, $totalWidth, $theme, $height, $rowLines);
            }

            $renderY += $height;
        }
    }

    /**
     * @param list<int> $columnWidths
     */
    private function layoutFor(array $columnWidths): TableLayout
    {
        $rows = $this->cellText();

        return $this->layoutMemo->at(
            implode(',', $columnWidths),
            static fn (): TableLayout => TableLayout::forRows($columnWidths, $rows),
        );
    }

    /**
     * @return list<int>
     */
    private function columnWidthsFor(Rect $area): array
    {
        $showScrollbar = Scrollbar::needsScrollbar($this->rowCount(), max(1, $area->height - $this->headerHeight()));

        return $this->computeColumnWidths($showScrollbar ? $area->width - 1 : $area->width);
    }

    /**
     * Cell text for measuring, whichever row representation is in use.
     *
     * @return list<list<string>>
     */
    private function cellText(): array
    {
        if ($this->typedRows === []) {
            return $this->rows;
        }

        $rows = [];
        foreach ($this->typedRows as $typedRow) {
            $rows[] = self::textOf($typedRow);
        }

        return $rows;
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

    /**
     * @param list<int>          $columnWidths
     * @param list<list<string>> $rowLines
     */
    private function renderPlainRow(Frame $frame, int $startX, int $renderY, int $rowIndex, array $columnWidths, int $totalWidth, Theme $theme, int $height, array $rowLines): void
    {
        $isCursor = $rowIndex === $this->cursor;
        $isSelected = $rowIndex === $this->selected;
        $reverse = ($isCursor && $this->focused) || $isSelected;

        $cellStyle = match (true) {
            $this->focused => $theme->base,
            default => $theme->dim,
        };

        if ($reverse) {
            $cellStyle = $cellStyle->reverse();
            $cellStyle->apply($frame, new Rect($startX, $renderY, $totalWidth, $height));
        }

        $cellX = $startX;
        foreach (array_keys($this->columns) as $columnIndex) {
            $width = $columnWidths[$columnIndex] ?? 0;
            if ($width <= 0) {
                continue;
            }

            for ($line = 0; $line < $height; ++$line) {
                $frame->putStyled($cellX, $renderY + $line, $rowLines[$columnIndex][$line] ?? '', $cellStyle);
            }

            $cellX += $width;
        }
    }

    /**
     * @param list<int>          $columnWidths
     * @param list<list<string>> $rowLines
     */
    private function renderTypedRow(Frame $frame, int $startX, int $renderY, int $rowIndex, Row $typedRow, array $columnWidths, int $totalWidth, Theme $theme, int $height, array $rowLines): void
    {
        if (!$typedRow->selectable) {
            $headerStyle = $theme->headerStyle;
            $cell = $typedRow->cells[0] ?? '';
            $label = CharWidth::truncateToWidth($cell instanceof Span ? $cell->content : $cell, $totalWidth);
            $frame->putStyled($startX, $renderY, $label, $headerStyle);

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

        $rowStyle = $typedRow->style ?? $defaultStyle;
        if ($reverse && $typedRow->style !== null) {
            $rowStyle = $rowStyle->reverse();
        }

        $cellX = $startX;
        foreach (array_keys($this->columns) as $columnIndex) {
            $width = $columnWidths[$columnIndex] ?? 0;
            if ($width <= 0) {
                continue;
            }

            $cell = $typedRow->cells[$columnIndex] ?? '';
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

    private function ensureCursorVisible(Rect $area): self
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->columnWidthsFor($area));
        $offset = $this->scrollOffset;

        if ($this->cursor < $offset) {
            $offset = $this->cursor;
        }

        while ($offset < $this->cursor && $layout->rowAtLine($offset, $viewportHeight - 1) !== null
            && $layout->rowAtLine($offset, $viewportHeight - 1) < $this->cursor
        ) {
            ++$offset;
        }

        return $this->cloneWith(scrollOffset: $offset);
    }
}
