<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Overflow;
use Elmphant\Tui\Widget\Widget;

/**
 * Draw all rows without navigation; use Table for scrolling or large datasets.
 */
final readonly class StaticTable implements Widget, MeasureHeight
{
    use Cloneable;

    private const OVERHEAD_PER_COLUMN = 3;

    private const MIN_COLUMN = 4;

    private const SMALLEST_WINDOW = 64;

    /**
     * @param list<string>        $headers
     * @param list<list<string>>  $rows
     * @param ?list<list<string>> $measured rows the columns are sized for, when not the rows drawn
     */
    private function __construct(
        private array $headers,
        private array $rows,
        private BorderStyle $border = BorderStyle::Solid,
        private Overflow $overflow = Overflow::Stack,
        private ?array $measured = null,
        private int $minWidth = 0,
        private StaticTableMemo $memo = new StaticTableMemo(),
    ) {
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function new(array $headers, array $rows): self
    {
        return new self($headers, $rows);
    }

    public function withBorder(BorderStyle $border): self
    {
        return $this->rebuiltWith(border: $border);
    }

    public function withOverflow(Overflow $overflow): self
    {
        return $this->rebuiltWith(overflow: $overflow);
    }

    /**
     * Measure against all rows so filtering to a subset does not change column widths.
     *
     * @param list<list<string>> $rows
     */
    public function withWidthsFrom(array $rows): self
    {
        return $this->rebuiltWith(measured: $rows);
    }

    public function withMinWidth(int $columns): self
    {
        return $this->rebuiltWith(minWidth: $columns);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(max($this->minWidth, self::widthOf($this->demands()))),
            Constraint::min(1),
        );
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        return $this->unwrappedLineCount($width) ?? count($this->linesFor($width));
    }

    /**
     * @infection-ignore-all every line is the same width, so which line or window is read only changes the cost
     */
    public function drawnWidth(int $width): int
    {
        return min($width, CharWidth::stringWidth($this->linesFor($width, 1)[0]));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $style = $context->theme->base;
        $clip = $frame->clip();
        $first = max(0, $clip->y - $area->y);
        $lines = $this->linesFor($area->width, min($area->height, $clip->bottom() - $area->y));
        $last = min(count($lines), $area->height, $clip->bottom() - $area->y);

        $frame->pushClip($area);

        try {
            for ($row = $first; $row < $last; ++$row) {
                $frame->putStyled($area->x, $area->y + $row, $lines[$row], $style);
            }
        } finally {
            $frame->popClip();
        }
    }

    /**
     * Compute height from row count only when columns fit without wrapping; otherwise return null.
     */
    private function unwrappedLineCount(int $width): ?int
    {
        $widest = $this->demands();

        if (self::spaceFor($widest, $width) < 0) {
            return null;
        }

        $rules = $this->headers === [] ? 2 : 3;
        $rowLines = $this->memo->rowLines ??= array_sum(array_map(self::linesOfRow(...), $this->rows));

        return $rules + ($this->headers === [] ? 0 : self::linesOfRow($this->headers)) + $rowLines;
    }

    private function rebuiltWith(mixed ...$overrides): self
    {
        return $this->cloneWith(...$overrides, memo: new StaticTableMemo());
    }

    /**
     * Include every rendering field in the cache key; entries outlive table instances.
     *
     * @infection-ignore-all a memo: computing the key again gives the same key
     */
    private function contentKey(): string
    {
        return $this->memo->keyed ??= hash('xxh3', serialize([
            $this->headers,
            $this->rows,
            $this->measured,
            $this->border,
            $this->overflow,
            $this->minWidth,
        ]));
    }

    /**
     * @infection-ignore-all a larger window or a missed reuse only draws more; the tests pin a window too small
     *
     * @return list<string>
     */
    private function linesFor(int $width, ?int $upTo = null): array
    {
        // Rounded up to a window, so scrolling a line asks for the window already drawn.
        if (null !== $upTo) {
            $upTo = max(self::SMALLEST_WINDOW, 1 << (int)ceil(log(max(1, $upTo), 2)));
        }

        // Reuse a cached window only for smaller windows, never for the whole table.
        $memo = $this->memo;
        if ($memo->builtFor === $width
            && (null === $memo->builtUpTo || (null !== $upTo && $memo->builtUpTo >= $upTo))
        ) {
            return $memo->built;
        }

        /** @var list<string> $lines */
        $lines = WrapCache::remember(
            $this->contentKey() . ':lines:' . $width . ':' . ($upTo ?? 'all'),
            fn (): array => $this->draw($width, $upTo),
        );

        $memo->built = $lines;
        $memo->builtFor = $width;
        $memo->builtUpTo = $upTo;

        return $lines;
    }

    /**
     * Size columns from all rows, but draw only as far as $upTo requires.
     *
     * @return list<string>
     */
    private function draw(int $width, ?int $upTo = null): array
    {
        $widest = $this->demands();
        $rows = array_slice($this->rows, 0, $upTo);

        if (self::spaceFor($widest, $width) >= 0) {
            $widths = self::grown($widest, min($width, max($this->minWidth, self::widthOf($widest))), $widest);

            return $this->drawn($this->headers, $rows, $widths);
        }

        $floors = self::capped($this->wordFloors(), $width);

        if (self::spaceFor($floors, $width) < 0 && $this->overflow === Overflow::Stack) {
            $kept = $this->keptColumns($floors, $width);

            if ($kept !== []) {
                return $this->drawnWithStackedColumns($kept, $floors, $width, $rows);
            }
        }

        if (self::spaceFor($floors, $width) < 0) {
            return $this->drawn($this->headers, $rows, $this->sharedWidths($width));
        }

        $widths = self::grown($floors, $width, $widest);

        return $this->drawn($this->headers, $rows, $widths);
    }

    /**
     * Keep the first column and as many narrow columns as fit; move the rest to labelled detail lines.
     *
     * @param list<int> $demands
     *
     * @return list<int> column indexes, in their original order
     */
    private function keptColumns(array $demands, int $width): array
    {
        $order = $demands;
        asort($order);
        $kept = [0];

        foreach (array_keys($order) as $column) {
            $candidate = $column === 0 ? $kept : [...$kept, $column];

            if (self::spaceFor(self::at($demands, $candidate, 0), $width) >= 0) {
                $kept = $candidate;
            }
        }

        sort($kept);

        return self::spaceFor(self::at($demands, $kept, 0), $width) >= 0 ? $kept : [];
    }

    /**
     * @param list<int>          $kept
     * @param list<int>          $demands
     * @param list<list<string>> $sourceRows
     *
     * @return list<string>
     */
    private function drawnWithStackedColumns(array $kept, array $demands, int $width, array $sourceRows): array
    {
        $demoted = array_values(array_diff(array_keys($demands), $kept));
        $kepts = self::at($demands, $kept, 0);
        $widths = self::grown($kepts, $width, [$width, ...array_slice($kepts, 1)]);
        $host = $widths[0];
        $headers = [];
        if ($this->headers !== []) {
            $headers = self::at($this->headers, $kept);
            $headers[0] = self::stacked([$headers[0], ...self::at($this->headers, $demoted)], $host);
        }
        $rows = [];

        foreach ($sourceRows as $index => $row) {
            if ($index > 0) {
                $rows[] = null;
            }

            $cells = self::at($row, $kept);
            $cells[0] = self::stacked([$cells[0], ...self::at($row, $demoted)], $host);
            $rows[] = $cells;
        }

        return $this->drawn($headers, $rows, $widths);
    }

    /**
     * Truncate each field to one line so values stay aligned with their header labels.
     *
     * @param list<string> $values
     */
    private static function stacked(array $values, int $width): string
    {
        $lines = array_map(
            static fn (string $value): string => TextWrap::ellipsize(trim($value), $width),
            $values,
        );

        return implode("\n", $lines);
    }

    /**
     * @param list<string>            $headers
     * @param list<list<string>|null> $rows    a null row is a rule across the table
     * @param list<int>               $widths
     *
     * @return list<string>
     */
    private function drawn(array $headers, array $rows, array $widths): array
    {
        return TableGrid::draw($headers, $rows, $widths, $this->border->glyphs());
    }

    /**
     * Fill the pane when overflow creates detail lines that need the extra width.
     *
     * @param list<int> $widths
     * @param list<int> $wants
     *
     * @return list<int>
     */
    private static function grown(array $widths, int $width, array $wants): array
    {
        if ($widths === []) {
            return [];
        }

        $spare = self::spaceFor($widths, $width);
        $needs = array_map(static fn (int $has, int $want): int => max(0, $want - $has), $widths, $wants);
        $total = array_sum($needs);

        foreach ($needs as $column => $need) {
            $widths[$column] += $total === 0 ? 0 : min($need, (int)floor($spare * $need / $total));
        }

        $widths[$total === 0 ? count($widths) - 1 : (int)array_search(max($needs), $needs, true)] += self::spaceFor($widths, $width);

        return array_values($widths);
    }

    /**
     * @param array<int, int> $widths
     */
    private static function spaceFor(array $widths, int $width): int
    {
        return $width - self::widthOf($widths);
    }

    /**
     * @param array<int, int> $widths
     */
    private static function widthOf(array $widths): int
    {
        return array_sum($widths) + count($widths) * self::OVERHEAD_PER_COLUMN + 1;
    }

    /**
     * @template TValue of string|int
     *
     * @param array<int, TValue> $values
     * @param list<int>          $columns
     * @param TValue             $missing
     *
     * @return list<TValue>
     */
    private static function at(array $values, array $columns, string|int $missing = ''): array
    {
        return array_map(static fn (int $column): string|int => $values[$column] ?? $missing, $columns);
    }

    /**
     * @infection-ignore-all a memo over measuring(): measuring again gives the same widths
     *
     * @return list<int>
     */
    private function demands(): array
    {
        if (null !== $this->memo->demanded) {
            return $this->memo->demanded;
        }

        /** @var list<int> $demanded */
        $demanded = WrapCache::remember($this->contentKey() . ':columns', $this->measuring(...));

        return $this->memo->demanded = $demanded;
    }

    /**
     * @return list<int>
     */
    private function measuring(): array
    {
        $widest = [];

        foreach ($this->measured ?? $this->rows as $row) {
            foreach ($row as $column => $cell) {
                $width = self::widestLineOf($cell);

                if ($width > ($widest[$column] ?? 0)) {
                    $widest[$column] = $width;
                }
            }
        }

        $demands = [];

        for ($column = 0, $count = $this->columnCount(); $column < $count; ++$column) {
            $demands[] = max(self::widestLineOf($this->headers[$column] ?? ''), $widest[$column] ?? 0);
        }

        return $demands;
    }

    private function columnCount(): int
    {
        return max([count($this->headers), ...array_map(count(...), $this->measured ?? $this->rows)]);
    }

    private static function widestLineOf(string $cell): int
    {
        return max(array_map(CharWidth::stringWidth(...), explode("\n", $cell)));
    }

    /**
     * @param list<string> $cells
     */
    private static function linesOfRow(array $cells): int
    {
        return max([1, ...array_map(static fn (string $cell): int => substr_count($cell, "\n") + 1, $cells)]);
    }

    /**
     * Cap each column's minimum share so one long value cannot dictate the entire layout.
     *
     * @param list<int> $floors
     *
     * @return list<int>
     */
    private static function capped(array $floors, int $width): array
    {
        $share = max(self::MIN_COLUMN, intdiv($width, count($floors)));

        return array_map(static fn (int $floor): int => min($floor, $share), $floors);
    }

    /**
     * @return list<int>
     */
    private function wordFloors(): array
    {
        $floors = $this->columnFloors($this->columnCount());

        foreach ($this->measured ?? $this->rows as $row) {
            foreach ($row as $column => $cell) {
                foreach (self::words($cell) as $word) {
                    $floors[$column] = max($floors[$column] ?? self::MIN_COLUMN, CharWidth::stringWidth($word));
                }
            }
        }

        return array_values($floors);
    }

    /**
     * @return list<int>
     */
    private function sharedWidths(int $width): array
    {
        $count = $this->columnCount();
        $floors = $this->columnFloors($count);
        $budget = $width - ($count * self::OVERHEAD_PER_COLUMN + 1);

        // If minimums cannot fit, share available space evenly to keep the frame within the pane; clip below one cell per column.
        if ($budget < array_sum($floors)) {
            return $budget < $count ? $floors : self::spread($budget, $count);
        }

        $widths = $floors;

        for ($spare = $budget - array_sum($floors); $spare > 0; --$spare) {
            $narrowest = 0;

            foreach ($widths as $column => $width) {
                if ($width <= $widths[$narrowest]) {
                    $narrowest = $column;
                }
            }

            ++$widths[$narrowest];
        }

        return array_values($widths);
    }

    /**
     * @return list<int>
     */
    private static function spread(int $budget, int $count): array
    {
        $share = intdiv($budget, $count);
        $remainder = $budget % $count;

        $widths = [];
        for ($column = 0; $column < $count; ++$column) {
            $widths[] = $share + ($column < $remainder ? 1 : 0);
        }

        return $widths;
    }

    /**
     * @return list<int>
     */
    private function columnFloors(int $count): array
    {
        $floors = [];

        for ($column = 0; $column < $count; ++$column) {
            $words = self::words($this->headers[$column] ?? '');
            $floors[] = max(self::MIN_COLUMN, ...array_map(CharWidth::stringWidth(...), $words === [] ? [''] : $words));
        }

        return $floors;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/', $text, flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
