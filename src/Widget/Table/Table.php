<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisResult;
use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
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
use InvalidArgumentException;

/**
 * Draw only visible rows with cursor, selection and scrolling; column constraints keep cells within the width.
 * Normalize plain cell arrays to Row values so both inputs share identity and rendering rules.
 */
final readonly class Table implements Committing, Focusable, KeybindingsAware, MeasureHeight, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    /**
     * Share row measurements across clones so cursor moves do not remeasure the table.
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
        private int $columnGap = 1,
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

    public function withColumnGap(int $gap): self
    {
        if ($gap < 0) {
            throw new InvalidArgumentException(sprintf('Table column gap must be non-negative, got %d', $gap));
        }

        return $this->cloneWith(columnGap: $gap);
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
     * @param list<Row> $rows
     */
    public function withRows(array $rows): self
    {
        $keys = array_map(static fn (Row $row): string => $row->key(), $rows);

        return $this->cloneWith(
            rows: $rows,
            cursor: self::selectablePlaceNear(Places::cursorAfter(($this->rows[$this->cursor] ?? null)?->key(), $keys, $this->cursor), $rows),
            selected: Places::placeOf($this->selectedRow()?->key(), $keys, $this->selected ?? 0),
            layoutMemo: self::newLayoutMemo(),
        );
    }

    public function withSelectedIndex(?int $selected): self
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
     * Keep externally moved cursors visible too, such as when a search field handles navigation.
     */
    public function withCursor(int $cursor): self
    {
        return $this->cloneWith(cursor: self::selectablePlaceNear(max(0, min($cursor, $this->rowCount() - 1)), $this->rows), followCursor: true);
    }

    public function selectedIndex(): ?int
    {
        return $this->selected;
    }

    public function cursorRow(): ?Row
    {
        return $this->rows[$this->cursor] ?? null;
    }

    public function selectedRow(): ?Row
    {
        return $this->selected === null ? null : $this->rows[$this->selected] ?? null;
    }

    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && ($this->rows[$this->cursor] ?? null)?->selectable === true
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT);
    }

    /**
     * Follow the cursor unless the view was explicitly scrolled away, including after external cursor changes.
     */
    public function scrollOffset(Rect $area): int
    {
        return $this->followCursor
            ? $this->cursorVisibleOffset($area)
            : $this->clampedScrollOffset($area);
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
        [$showScrollbar, $placement] = $this->scrollbarAndColumns($area);

        $frame->pushClip($area);
        try {
            if ($this->header) {
                $this->renderHeader($frame, $area, $placement, $theme);
            }
            $this->renderRows($frame, $area, $placement, $theme, $this->drawsFocused($context));

            if ($showScrollbar) {
                $layout = $this->layoutFor($placement);
                $topRow = $this->scrollOffset($area);

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
        return $this->headerHeight() + $this->layoutFor($this->columnPlacement($width))->totalHeight;
    }

    /**
     * After reload, move the cursor off rows that cannot be selected.
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
            $typedRows[] = Row::new(...$cells);
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

    private function rowAPageAway(int $direction, int $visibleRows, Rect $area): int
    {
        $layout = $this->layoutFor($this->placementFor($area));

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
        $displayOffset = $this->scrollOffset($area);

        if ($message->isClick() && $message->isIn($area)) {
            $localY = $message->y - $area->y - $this->headerHeight();
            $rowIndex = $localY >= 0
                ? $this->layoutFor($this->placementFor($area))->rowAtLine($displayOffset, $localY)
                : null;

            if ($rowIndex !== null && $this->isRowSelectable($rowIndex)) {
                return new UpdateResult($this->cloneWith(cursor: $rowIndex, selected: $rowIndex, followCursor: true));
            }
        }

        if ($message->isIn($area)) {
            if ($message->action === MouseAction::WheelDown) {
                return new UpdateResult($this->scrolledDown($message->wheelSteps(), $displayOffset, $area));
            }

            if ($message->action === MouseAction::WheelUp) {
                return new UpdateResult($this->scrolledUp($message->wheelSteps(), $displayOffset, $area));
            }
        }

        return new UpdateResult($this);
    }

    private function renderHeader(Frame $frame, Rect $area, AxisResult $placement, Theme $theme): void
    {
        $headerStyle = $theme->headerStyle->underline();

        foreach ($this->columns as $index => $column) {
            $title = CharWidth::truncateToWidth($column->title(), $placement->sizes[$index]);
            $frame->putStyled($area->x + $placement->offsets[$index], $area->y, $title, $headerStyle);
        }
    }

    private function renderRows(Frame $frame, Rect $area, AxisResult $placement, Theme $theme, bool $focused): void
    {
        $layout = $this->layoutFor($placement);
        $totalWidth = self::spanWidthOf($placement);
        $renderY = $area->y + $this->headerHeight();

        // Use the clip as the viewport; a scrolling parent may pass the full content area.
        $lastVisibleY = $frame->clip()->bottom();
        $firstRow = $this->scrollOffset($area);
        $renderY -= $firstRow === $this->scrollOffset ? $this->lineOffset : 0;

        // A tall row may begin above the body, so keep it from overwriting the header.
        $frame->pushClip(new Rect($area->x, $area->y + $this->headerHeight(), $area->width, $area->height - $this->headerHeight()));

        try {
            for ($rowIndex = $firstRow; $rowIndex < $this->rowCount(); ++$rowIndex) {
                if ($renderY >= $lastVisibleY) {
                    break;
                }

                $rowArea = new Rect($area->x, $renderY, $totalWidth, $layout->heightOf($rowIndex));
                $this->renderRow($frame, $rowArea, $rowIndex, $placement, $theme, $focused);

                $renderY += $rowArea->height;
            }
        } finally {
            $frame->popClip();
        }
    }

    private function renderRow(Frame $frame, Rect $rowArea, int $rowIndex, AxisResult $placement, Theme $theme, bool $focused): void
    {
        $row = $this->rows[$rowIndex];

        if ($row->spansAllColumns) {
            foreach (TableLayout::spanningLines(self::spanWidthOf($placement), $row) as $lineIndex => $line) {
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
            $frame->fillStyled($rowArea, $defaultStyle);
        }

        $rowStyle = $row->style ?? $defaultStyle;
        if ($filled && $row->style !== null) {
            $rowStyle = $rowStyle->readableOn($defaultStyle);
        }

        $rowLines = TableLayout::linesOfRow($placement->sizes, $row, $this->wraps());

        foreach (array_keys($this->columns) as $columnIndex) {
            $cellX = $rowArea->x + $placement->offsets[$columnIndex];
            $cell = $row->cells[$columnIndex] ?? '';
            $cellStyle = $cell instanceof Span
                ? $cell->resolvedIn($theme)->style
                : null;

            $cellRenderStyle = match (true) {
                $cellStyle === null => $rowStyle,
                $filled => $cellStyle->readableOn($defaultStyle),
                default => $cellStyle,
            };

            for ($line = 0; $line < $rowArea->height; ++$line) {
                $frame->putStyled($cellX, $rowArea->y + $line, $rowLines[$columnIndex][$line] ?? '', $cellRenderStyle);
            }
        }
    }

    private function layoutFor(AxisResult $placement): TableLayout
    {
        $spanWidth = self::spanWidthOf($placement);

        return $this->layoutMemo->at(
            implode(',', $placement->sizes) . '|' . $spanWidth,
            fn (): TableLayout => TableLayout::forRows($placement->sizes, $this->rows, $this->wraps(), $spanWidth),
        );
    }

    private static function spanWidthOf(AxisResult $placement): int
    {
        $last = array_key_last($placement->sizes);

        return $last === null ? 0 : $placement->offsets[$last] + $placement->sizes[$last];
    }

    /**
     * @return list<Wrap>
     */
    private function wraps(): array
    {
        return array_map(static fn (Column $column): Wrap => $column->wrap(), $this->columns);
    }

    private function placementFor(Rect $area): AxisResult
    {
        return $this->scrollbarAndColumns($area)[1];
    }

    /**
     * Check total wrapped height, not row count; adding a scrollbar only narrows columns and increases wrapping.
     *
     * @return array{bool, AxisResult}
     */
    private function scrollbarAndColumns(Rect $area): array
    {
        $visibleLines = max(1, $area->height - $this->headerHeight());
        $placement = $this->columnPlacement($area->width);

        if (!Scrollbar::needsScrollbar($this->layoutFor($placement)->totalHeight, $visibleLines)) {
            return [false, $placement];
        }

        return [true, $this->columnPlacement($area->width - 1)];
    }

    private function columnPlacement(int $totalWidth): AxisResult
    {
        return Allocator::resolve(
            array_map(static fn (Column $column): ItemPolicy => $column->width()->policy(), $this->columns),
            $totalWidth,
            new AxisSpecification(gap: $this->columnGap),
        );
    }

    private function ensureCursorVisible(Rect $area): self
    {
        return $this->cloneWith(scrollOffset: $this->cursorVisibleOffset($area), lineOffset: 0);
    }

    /**
     * Scroll through a row taller than the viewport before advancing to the next row.
     */
    private function scrolledDown(int $steps, int $fromRow, Rect $area): self
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->placementFor($area));
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
        $layout = $this->layoutFor($this->placementFor($area));
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

    private function clampedScrollOffset(Rect $area): int
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->placementFor($area));

        return ScrollWindow::clamped($this->scrollOffset, $layout->maxOffset($viewportHeight));
    }

    private function cursorVisibleOffset(Rect $area): int
    {
        $viewportHeight = max(1, $area->height - $this->headerHeight());
        $layout = $this->layoutFor($this->placementFor($area));
        $linesOfTheCursorInView = min($layout->heightOf($this->cursor), $viewportHeight);
        $firstRowShowingTheCursor = $layout->firstRowAtOrAfter($layout->lineOf($this->cursor) + $linesOfTheCursorInView - $viewportHeight);
        $offset = max(min($this->scrollOffset, $this->cursor), $firstRowShowingTheCursor);

        return min($offset, $layout->maxOffset($viewportHeight));
    }
}
