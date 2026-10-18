<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Core\Message;
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
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Support\Places;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Wrap;

/**
 * A table the reader navigates: cursor, selection, mouse, and a scrolled viewport.
 *
 * Columns are sized by their Constraint to fill the width given, so cells
 * truncate or wrap rather than widening the table. Only the rows in view are
 * drawn, which is what makes it usable for unbounded row counts. For a bordered
 * table that just prints a small data set, see StaticTable.
 *
 * Rows given as plain cell text become Row values on the way in, so there is
 * one key path and one row renderer whichever shape the caller used.
 */
final readonly class Table implements Committing, Focusable, KeybindingsAware, MeasureHeight, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

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
        private int $cursor = 0,
        private ?int $selected = null,
        private int $scrollOffset = 0,
        private int $lineOffset = 0,
        private bool $followCursor = true,
        private bool $header = true,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        ?KeyedCache $layoutMemo = null,
    ) {
        $this->layoutMemo = $layoutMemo ?? self::newLayoutMemo();
    }

    /**
     * @param list<Column> $columns
     * @param list<Row>    $rows
     */
    public static function new(array $columns, array $rows): self
    {
        return new self(
            columns: $columns,
            rows: $rows,
            cursor: self::firstSelectablePlace($rows),
        );
    }

    /**
     * @param list<Column>       $columns
     * @param list<list<string>> $cells
     */
    public static function fromCells(array $columns, array $cells): self
    {
        return new self(
            columns: $columns,
            rows: self::asTypedRows($cells),
        );
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
     * @param list<list<string>> $cells
     */
    public function withCells(array $cells): self
    {
        return $this->withRows(self::asTypedRows($cells));
    }

    /**
     * The cursor and the selection stay on the rows they were on, matched by
     * Row::key().
     *
     * @param list<Row> $rows
     */
    public function withRows(array $rows): self
    {
        $keys = array_map(static fn (Row $row): string => $row->key(), $rows);

        return $this->cloneWith(
            rows: $rows,
            cursor: self::selectablePlaceNear(Places::cursorAfter(($this->rows[$this->cursor] ?? null)?->key(), $keys, $this->cursor), $rows),
            selected: Places::placeOf($this->selectedTypedRow()?->key(), $keys, $this->selected ?? 0),
            layoutMemo: self::newLayoutMemo(),
        );
    }

    public function withSelected(?int $selected): self
    {
        return $this->cloneWith(selected: $selected !== null && isset($this->rows[$selected]) ? $selected : null);
    }

    /**
     * Scroll explicitly; the view stops following the cursor until it moves again.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: $scrollOffset, lineOffset: 0, followCursor: false);
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
        return $this->cloneWith(cursor: max(0, min($cursor, $this->rowCount() - 1)), followCursor: true);
    }

    public function selectedIndex(): ?int
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
        $row = $this->selectedTypedRow();

        return $row === null ? null : self::textOf($row);
    }

    public function selectedTypedRow(): ?Row
    {
        return $this->selected === null ? null : $this->rows[$this->selected] ?? null;
    }

    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && ($this->rows[$this->cursor] ?? null)?->selectable === true
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT);
    }

    public function scrollOffset(): int
    {
        return $this->scrollOffset;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $rowCount = $this->rowCount();

        return match (true) {
            $message instanceof KeyMsg && $rowCount > 0 => $this->handleKey($message, $area),
            $message instanceof MouseMsg => $this->handleMouse($message, $area),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height <= 0) {
            return;
        }

        $theme = $context->theme;
        $visibleRows = $area->height - $this->headerHeight();
        [$showScrollbar, $columnWidths] = $this->scrollbarAndColumnWidths($area);

        $frame->pushClip($area);
        try {
            if ($this->header) {
                $this->renderHeader($frame, $area, $columnWidths, $theme);
            }
            $this->renderRows($frame, $area, $columnWidths, $theme, $this->drawsFocused($context));

            if ($showScrollbar) {
                $layout = $this->layoutFor($columnWidths);
                $topRow = $this->displayScrollOffset($area);

                Scrollbar::renderFor(
                    $frame,
                    new Rect($area->x, $area->y + $this->headerHeight(), $area->width, $visibleRows),
                    $layout->lineOf($topRow) + ($topRow === $this->scrollOffset ? $this->lineOffset : 0),
                    $layout->totalHeight,
                    $theme,
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
        return Constraint::allocate(array_map(static fn (Column $column): Constraint => $column->width(), $this->columns), $totalWidth);
    }

    /**
     * Off a row that cannot be chosen, since only a reload can seat the cursor on one.
     *
     * @param list<Row> $rows
     */
    private static function selectablePlaceNear(int $place, array $rows): int
    {
        for ($distance = 0, $count = count($rows); $distance < $count; ++$distance) {
            foreach ([$place + $distance, $place - $distance] as $candidate) {
                if (($rows[$candidate] ?? null)?->selectable === true) {
                    return $candidate;
                }
            }
        }

        return 0;
    }

    /**
     * @param list<Row> $rows
     */
    private static function firstSelectablePlace(array $rows): int
    {
        foreach ($rows as $place => $row) {
            if ($row->selectable) {
                return $place;
            }
        }

        return 0;
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
        return $this->rows[$index]->selectable;
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
            $bindings->matches($message, Actions::CURSOR_UP) => $this->withCursorAt($this->findNextSelectable($this->cursor, -1)),
            $bindings->matches($message, Actions::CURSOR_DOWN) => $this->withCursorAt($this->findNextSelectable($this->cursor, 1)),
            $bindings->matches($message, Actions::HOME) => $this->withCursorAt($this->findNextSelectable(-1, 1)),
            $bindings->matches($message, Actions::END) => $this->withCursorAt($this->findNextSelectable($rowCount, -1)),
            $bindings->matches($message, Actions::PAGE_DOWN) => $this->withCursorAt($this->rowAPageAway(1, $visibleRows, $area)),
            $bindings->matches($message, Actions::PAGE_UP) => $this->withCursorAt($this->rowAPageAway(-1, $visibleRows, $area)),
            $bindings->matches($message, Actions::SELECT) => $this->cloneWith(selected: $this->cursor),
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
            ? $layout->rowAtLine($this->cursor, $visibleRows) ?? $this->rowCount() - 1
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
    private function handleMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $displayOffset = $this->displayScrollOffset($area);

        if ($message->isClick() && $message->inRect($area)) {
            $localY = $message->y - $area->y - $this->headerHeight();
            $rowIndex = $localY >= 0
                ? $this->layoutFor($this->columnWidthsFor($area))->rowAtLine($displayOffset, $localY)
                : null;

            if ($rowIndex !== null && $this->isRowSelectable($rowIndex)) {
                return new UpdateResult($this->cloneWith(cursor: $rowIndex, selected: $rowIndex, followCursor: true));
            }
        }

        if ($message->inRect($area)) {
            if ($message->action === MouseAction::WheelDown) {
                return new UpdateResult($this->scrolledDown($message->wheelSteps(), $displayOffset, $area));
            }

            if ($message->action === MouseAction::WheelUp) {
                return new UpdateResult($this->scrolledUp($message->wheelSteps(), $displayOffset, $area));
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
            $width = $columnWidths[$index];
            $title = CharWidth::truncateToWidth($column->title(), $width);
            $frame->putStyled($cellX, $area->y, $title, $headerStyle);
            $cellX += $width;
        }
    }

    /**
     * @param list<int> $columnWidths
     */
    private function renderRows(Frame $frame, Rect $area, array $columnWidths, Theme $theme, bool $focused): void
    {
        $layout = $this->layoutFor($columnWidths);
        $totalWidth = array_sum($columnWidths);
        $renderY = $area->y + $this->headerHeight();

        // A scroll container sizes its child to the child's whole content and
        // clips the overflow, so the area is not the viewport — the clip is.
        // Reading rows past it would draw thousands of invisible lines.
        $lastVisibleY = $frame->clip()->bottom();
        $firstRow = $this->displayScrollOffset($area);
        $renderY -= $firstRow === $this->scrollOffset ? $this->lineOffset : 0;

        // The first row may start above the body when it is taller than the
        // viewport, and the header sits in the same clip as the rows.
        $frame->pushClip(new Rect($area->x, $area->y + $this->headerHeight(), $area->width, $area->height - $this->headerHeight()));

        try {
            for ($rowIndex = $firstRow; $rowIndex < $this->rowCount(); ++$rowIndex) {
                if ($renderY >= $lastVisibleY) {
                    break;
                }

                $rowArea = new Rect($area->x, $renderY, $totalWidth, $layout->heightOf($rowIndex));
                $this->renderRow($frame, $rowArea, $rowIndex, $columnWidths, $theme, $focused);

                $renderY += $rowArea->height;
            }
        } finally {
            $frame->popClip();
        }
    }

    /**
     * @param list<int> $columnWidths
     */
    private function renderRow(Frame $frame, Rect $rowArea, int $rowIndex, array $columnWidths, Theme $theme, bool $focused): void
    {
        $row = $this->rows[$rowIndex];

        if ($row->spansAllColumns) {
            foreach (TableLayout::spanningLines($columnWidths, $row) as $lineIndex => $line) {
                $frame->putStyled($rowArea->x, $rowArea->y + $lineIndex, $line, $theme->headerStyle);
            }

            return;
        }

        $isCursor = $rowIndex === $this->cursor;
        $isSelected = $rowIndex === $this->selected;
        $filled = ($isCursor && $focused) || $isSelected;

        $defaultStyle = match (true) {
            $isCursor && $focused => $theme->cursorStyle,
            $isSelected => $theme->selectedStyle,
            $focused => $theme->base,
            default => $theme->dim,
        };

        if ($filled) {
            $defaultStyle->apply($frame, $rowArea);
        }

        $rowStyle = $row->style ?? $defaultStyle;
        if ($filled && $row->style !== null) {
            $rowStyle = $rowStyle->readableOn($defaultStyle);
        }

        $rowLines = TableLayout::linesOfRow($columnWidths, $row, $this->wraps());
        $cellX = $rowArea->x;

        foreach (array_keys($this->columns) as $columnIndex) {
            $width = $columnWidths[$columnIndex];
            $cell = $row->cells[$columnIndex] ?? '';
            $cellStyle = $cell instanceof Span
                ? $cell->withResolvedToken($theme)->style
                : null;

            $cellRenderStyle = match (true) {
                $cellStyle === null => $rowStyle,
                $filled => $cellStyle->readableOn($defaultStyle),
                default => $cellStyle,
            };

            for ($line = 0; $line < $rowArea->height; ++$line) {
                $frame->putStyled($cellX, $rowArea->y + $line, $rowLines[$columnIndex][$line] ?? '', $cellRenderStyle);
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
            fn (): TableLayout => TableLayout::forRows($columnWidths, $this->rows, $this->wraps()),
        );
    }

    /**
     * @return list<Wrap>
     */
    private function wraps(): array
    {
        return array_map(static fn (Column $column): Wrap => $column->wrap(), $this->columns);
    }

    /**
     * @return list<int>
     */
    private function columnWidthsFor(Rect $area): array
    {
        return $this->scrollbarAndColumnWidths($area)[1];
    }

    /**
     * Whether the rows need a scrollbar, and the widths that leaves. Cells wrap,
     * so what has to fit is the lines the rows come to and not how many rows
     * there are; a scrollbar then takes a column, which can only wrap them
     * further, so needing one at the full width still needs one once narrowed.
     *
     * @return array{bool, list<int>}
     */
    private function scrollbarAndColumnWidths(Rect $area): array
    {
        $visibleLines = max(1, $area->height - $this->headerHeight());
        $widths = $this->columnWidths($area->width);

        if (!Scrollbar::needsScrollbar($this->layoutFor($widths)->totalHeight, $visibleLines)) {
            return [false, $widths];
        }

        return [true, $this->columnWidths($area->width - 1)];
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
        return $this->cloneWith(scrollOffset: $this->cursorVisibleOffset($area), lineOffset: 0);
    }

    /**
     * The offset actually drawn: it follows the cursor unless the view was
     * scrolled away from it, so a cursor moved from outside update() is still
     * on screen at the next frame.
     */
    private function displayScrollOffset(Rect $area): int
    {
        return $this->followCursor
            ? $this->cursorVisibleOffset($area)
            : $this->clampedScrollOffset($area);
    }

    /**
     * A row taller than the viewport cannot be read by stepping over it, so the
     * wheel walks down its lines first and only then moves to the next row.
     */
    private function scrolledDown(int $steps, int $fromRow, Rect $area): self
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->columnWidthsFor($area));
        $maxOffset = $layout->maxOffset($viewportHeight);
        $row = $fromRow;
        $line = $row === $this->scrollOffset ? $this->lineOffset : 0;

        for ($step = 0; $step < $steps; ++$step) {
            $remaining = $layout->heightOf($row) - $line - $viewportHeight;

            if ($remaining > 0) {
                ++$line;
                continue;
            }

            if ($row >= $maxOffset) {
                break;
            }

            ++$row;
            $line = 0;
        }

        return $this->cloneWith(scrollOffset: $row, lineOffset: $line, followCursor: false);
    }

    private function scrolledUp(int $steps, int $fromRow, Rect $area): self
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->columnWidthsFor($area));
        $row = $fromRow;
        $line = $row === $this->scrollOffset ? $this->lineOffset : 0;

        for ($step = 0; $step < $steps; ++$step) {
            if ($line > 0) {
                --$line;
                continue;
            }

            if ($row === 0) {
                break;
            }

            --$row;
            $line = max(0, $layout->heightOf($row) - $viewportHeight);
        }

        return $this->cloneWith(scrollOffset: $row, lineOffset: $line, followCursor: false);
    }

    /**
     * An offset past the end would draw blank rows the reader cannot scroll
     * back from.
     */
    private function clampedScrollOffset(Rect $area): int
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->columnWidthsFor($area));

        return ScrollWindow::clamped($this->scrollOffset, $layout->maxOffset($viewportHeight));
    }

    private function cursorVisibleOffset(Rect $area): int
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->columnWidthsFor($area));
        $linesOfTheCursorInView = min($layout->heightOf($this->cursor), $viewportHeight);
        $firstRowShowingTheCursor = $layout->firstRowAtOrAfter($layout->lineOf($this->cursor) + $linesOfTheCursorInView - $viewportHeight);
        $offset = max(min($this->scrollOffset, $this->cursor), $firstRowShowingTheCursor);

        return min($offset, $layout->maxOffset($viewportHeight));
    }
}
