<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Prose;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\RowExtents;
use Elmphant\Tui\Layout\RowIndex;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\SgrParser;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\SpanRenderer;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\AnsiTokenizer;
use Elmphant\Tui\Text\AnsiTokenType;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\Wrap;

final readonly class Paragraph implements Widget, MeasureHeight
{
    use Cloneable;

    private RowIndex $rowIndex;

    /**
     * @param list<Line> $lines
     */
    private function __construct(
        public array $lines = [],
        public Alignment $alignment = Alignment::Left,
        public Wrap $wrap = Wrap::WordWrap,
        public ?Style $style = null,
        public ?Sizing $sizingOverride = null,
        ?RowIndex $rowIndex = null,
    ) {
        $this->rowIndex = $rowIndex ?? RowIndex::of(count($lines));
    }

    /**
     * @param list<Line> $lines
     */
    public function withLines(array $lines): self
    {
        return $this->cloneWith(lines: $lines, rowIndex: RowIndex::of(count($lines)));
    }

    public function withAlignment(Alignment $alignment): self
    {
        return $this->cloneWith(alignment: $alignment);
    }

    public function withWrap(Wrap $wrap): self
    {
        return $this->cloneWith(wrap: $wrap);
    }

    public function withStyle(?Style $style): self
    {
        return $this->cloneWith(style: $style);
    }

    public function withSizing(?Sizing $sizing): self
    {
        return $this->cloneWith(sizingOverride: $sizing);
    }

    public static function fromText(string $text): self
    {
        $lines = array_map(
            static fn (string $line): Line => Line::raw(CharWidth::withTabsExpanded(self::afterLastCarriageReturn($line), 0)),
            explode("\n", $text),
        );

        return new self($lines);
    }

    /**
     * @param list<Line> $lines
     */
    public static function fromLines(array $lines): self
    {
        return new self($lines);
    }

    public static function fromMarkdown(string $markdown): self
    {
        return new self(SpanRenderer::fromMarkdown($markdown));
    }

    public static function fromAnsi(string $ansiText): self
    {
        $rawLines = explode("\n", $ansiText);
        $lines = [];
        $parser = new SgrParser();

        foreach ($rawLines as $rawLine) {
            $spans = [];
            $currentText = '';
            $column = 0;
            $currentForeground = $parser->foreground;
            $currentBackground = $parser->background;
            $currentAttributes = $parser->attributes;
            $rawLine = str_ends_with($rawLine, "\r") ? substr($rawLine, 0, -1) : $rawLine;
            foreach (AnsiTokenizer::tokenize($rawLine) as $token) {
                if ($token->type === AnsiTokenType::Text) {
                    $text = $token->content;
                    $carriageReturn = strrpos($text, "\r");
                    if ($carriageReturn !== false) {
                        $spans = [];
                        $currentText = '';
                        $column = 0;
                        $text = substr($text, $carriageReturn + 1);
                    }
                    $text = CharWidth::withTabsExpanded($text, $column);
                    $column += CharWidth::stringWidth($text);
                    $currentText .= $text;
                    continue;
                }

                if ($token->type !== AnsiTokenType::Csi || !str_ends_with($token->content, 'm')) {
                    continue;
                }

                if ($currentText !== '') {
                    $spans[] = self::spanFromSgrState($currentText, $currentForeground, $currentBackground, $currentAttributes);
                    $currentText = '';
                }
                $parser->parse(substr($token->content, 2, -1));
                $currentForeground = $parser->foreground;
                $currentBackground = $parser->background;
                $currentAttributes = $parser->attributes;
            }

            if ($currentText !== '') {
                $spans[] = self::spanFromSgrState($currentText, $currentForeground, $currentBackground, $currentAttributes);
            }

            $lines[] = new Line($spans);
        }

        return new self($lines);
    }

    public function sizing(): Sizing
    {
        return $this->sizingOverride ?? Sizing::of(
            Constraint::flex()->withBasis($this->widestLine()),
            Constraint::content(),
        );
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        if ($this->wrap === Wrap::NoWrap) {
            return count($this->lines);
        }

        return $this->rowsAt($width)->totalHeight();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->height < 1 || $area->width < 1) {
            return;
        }

        $baseStyle = $this->style !== null
            ? $this->style->inherit($context->theme->base)
            : $context->theme->base;

        // A scroll container sizes its child to the whole document and clips
        // the overflow, so the visible band is the clip, not the area. Every
        // source line yields at least one visual line, so slicing that many
        // source lines is always enough to fill it — and it keeps resolving
        // and wrapping proportional to the viewport.
        $visible = $area->intersect($frame->clip());
        if ($visible->height < 1) {
            return;
        }

        $skipped = $visible->y - $area->y;
        // A wrapped source line covers several visual rows, so the first
        // visible row is not the nth source line. Find which line owns it, and
        // how many of its rows sit above the band.
        [$firstLine, $rowsBefore] = $this->lineAtVisualRow($skipped, $area->width);
        $dropped = $skipped - $rowsBefore;

        $row = $skipped;
        $bottom = $skipped + $visible->height;

        foreach (array_slice($this->lines, $firstLine, $visible->height) as $line) {
            $line = $line->withResolvedTokens($context->theme);
            $text = self::textOf($line);

            foreach ($this->rowsToDraw($line, $text, $area->width) as [$start, $length]) {
                if ($dropped > 0) {
                    --$dropped;
                    continue;
                }
                if ($row >= $bottom) {
                    return;
                }

                $this->drawRow($frame, $line, $text, $start, $length, $area, $area->y + $row, $baseStyle);
                ++$row;
            }
        }
    }

    /**
     * A terminal shows only what follows a bare CR; a CRLF hides nothing.
     */
    private static function afterLastCarriageReturn(string $line): string
    {
        $line = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
        $carriageReturn = strrpos($line, "\r");

        return $carriageReturn === false ? $line : substr($line, $carriageReturn + 1);
    }

    private function widestLine(): int
    {
        $width = 0;
        foreach ($this->lines as $line) {
            $width = max($width, $line->width());
        }

        return $width;
    }

    /**
     * Which source line owns visual row $target, and how many rows precede it.
     *
     * @return array{int, int}
     */
    private function lineAtVisualRow(int $target, int $width): array
    {
        if ($target <= 0) {
            return [0, 0];
        }

        if ($this->wrap === Wrap::NoWrap) {
            return [$target, $target];
        }

        $rows = $this->rowsAt($width);
        $line = $rows->rowAtLine($target);

        return $line === null
            ? [count($this->lines), $rows->totalHeight()]
            : [$line, $rows->startOf($line)];
    }

    private function rowsAt(int $width): RowExtents
    {
        return $this->rowIndex->at($width, fn (int $index): int => $this->rowsOfLine($this->lines[$index], $width));
    }

    /**
     * Wrapping is the expensive part and a line narrower than the column
     * cannot wrap, so ask how wide it is before wrapping it.
     */
    private function rowsOfLine(Line $line, int $width): int
    {
        return $line->width() <= $width
            ? 1
            : count($this->wrappedRows($line, $width));
    }

    /**
     * Where a line breaks depends on its characters and the width alone, so
     * measuring and rendering the same text share one cached entry.
     *
     * A row is a byte offset and length into the line's text. Rows do not
     * always tile: a break at a space drops that space, but a space that is
     * itself too wide for the row starts the next one instead.
     *
     * @return list<array{int, int}>
     */
    private function wrappedRows(Line $line, int $width, ?string $text = null): array
    {
        $text ??= self::textOf($line);

        /** @var list<array{int, int}> */
        return WrapCache::remember(
            $width . ':' . hash('xxh3', $text),
            static fn (): array => preg_match('/[^\x20-\x7e]/', $text) === 0
                ? self::plainRowBounds($text, $width)
                : self::rowBounds(Grapheme::split($text), $width),
        );
    }

    /**
     * rowBounds() for printable ASCII, where a byte is a cell: it looks only
     * where a row can break, so a 1 MB line wraps in milliseconds, not 100.
     *
     * @return list<array{int, int}>
     */
    private static function plainRowBounds(string $text, int $maxWidth): array
    {
        $rows = [];
        $rowStart = 0;
        $length = strlen($text);

        while ($length - $rowStart > $maxWidth) {
            $space = strrpos(substr($text, $rowStart, $maxWidth + 1), ' ');
            if ($space === false) {
                $rows[] = [$rowStart, $maxWidth];
                $rowStart += $maxWidth;
                continue;
            }
            $rows[] = [$rowStart, $space];
            $rowStart += $space + 1;
        }

        $rows[] = [$rowStart, $length - $rowStart];

        return $rows;
    }

    private static function textOf(Line $line): string
    {
        $text = '';
        foreach ($line->spans as $span) {
            $text .= $span->content;
        }

        return $text;
    }

    /**
     * @param list<string> $characters
     *
     * @return list<array{int, int}>
     */
    private static function rowBounds(array $characters, int $maxWidth): array
    {
        $rows = [];
        $rowStart = 0;
        $currentWidth = 0;
        $lastSpaceOffset = -1;
        $widthAtLastSpace = 0;

        foreach ($characters as $index => $character) {
            $characterWidth = CharWidth::graphemeWidth($character);

            if ($characterWidth === 0) {
                continue;
            }

            if ($character === ' ') {
                $lastSpaceOffset = $index - $rowStart;
                $widthAtLastSpace = $currentWidth;
            }

            if ($currentWidth + $characterWidth > $maxWidth && $index > $rowStart) {
                if ($lastSpaceOffset >= 0) {
                    $rows[] = [$rowStart, $lastSpaceOffset];
                    $rowStart += $lastSpaceOffset + 1;
                    $currentWidth = $currentWidth - $widthAtLastSpace - 1 + $characterWidth;
                } else {
                    $rows[] = [$rowStart, $index - $rowStart];
                    $rowStart = $index;
                    $currentWidth = $characterWidth;
                }
                $lastSpaceOffset = -1;
                $widthAtLastSpace = 0;
                continue;
            }

            $currentWidth += $characterWidth;
        }

        $rows[] = [$rowStart, count($characters) - $rowStart];

        $byteAt = [0];
        foreach ($characters as $index => $character) {
            $byteAt[$index + 1] = $byteAt[$index] + strlen($character);
        }

        return array_map(
            static fn (array $row): array => [$byteAt[$row[0]], $byteAt[$row[0] + $row[1]] - $byteAt[$row[0]]],
            $rows,
        );
    }

    /**
     * @return list<array{int, int}>
     */
    private function rowsToDraw(Line $line, string $text, int $width): array
    {
        return $this->wrap === Wrap::NoWrap || $line->width() <= $width
            ? [[0, strlen($text)]]
            : $this->wrappedRows($line, $width, $text);
    }

    private function drawRow(Frame $frame, Line $line, string $text, int $start, int $length, Rect $area, int $y, Style $baseStyle): void
    {
        $rowText = substr($text, $start, $length);
        $right = $area->x + $area->width;
        $x = $area->x + $this->alignmentOffset(CharWidth::stringWidth($rowText), $area->width);

        foreach (self::runsOf($line, $baseStyle, $start, $rowText) as [$run, $style]) {
            if ($x >= $right) {
                return;
            }

            $x += $frame->putStyled($x, $y, CharWidth::truncateToWidth($run, $right - $x), $style);
        }
    }

    /**
     * The row cut where the style changes. A cluster belongs to the span it
     * starts in, so one that straddles a span boundary is drawn whole.
     *
     * @return list<array{string, Style}>
     */
    private static function runsOf(Line $line, Style $baseStyle, int $offset, string $rowText): array
    {
        $lineStyle = $line->style?->inherit($baseStyle) ?? $baseStyle;

        $starts = [];
        $styles = [];
        $position = 0;
        foreach ($line->spans as $span) {
            $starts[] = $position;
            $styles[] = $span->style?->inherit($lineStyle) ?? $lineStyle;
            $position += strlen($span->content);
        }

        $runs = [];
        $spanIndex = 0;
        $runSpan = -1;
        $runText = '';
        $runStyle = null;

        foreach (Grapheme::split($rowText) as $grapheme) {
            while (isset($starts[$spanIndex + 1]) && $starts[$spanIndex + 1] <= $offset) {
                ++$spanIndex;
            }

            if ($spanIndex !== $runSpan) {
                if ($runStyle !== null) {
                    $runs[] = [$runText, $runStyle];
                }
                $runText = '';
                $runStyle = $styles[$spanIndex] ?? $lineStyle;
                $runSpan = $spanIndex;
            }

            $runText .= $grapheme;
            $offset += strlen($grapheme);
        }

        if ($runStyle !== null) {
            $runs[] = [$runText, $runStyle];
        }

        return $runs;
    }

    private function alignmentOffset(int $lineWidth, int $areaWidth): int
    {
        return match ($this->alignment) {
            Alignment::Left => 0,
            Alignment::Center => max(0, (int)(($areaWidth - $lineWidth) / 2)),
            Alignment::Right => max(0, $areaWidth - $lineWidth),
        };
    }

    private static function spanFromSgrState(string $text, int $foreground, int $background, int $attributes): Span
    {
        if ($foreground === 0 && $background === 0 && $attributes === 0) {
            return new Span($text);
        }

        return new Span($text, new Style(
            foreground: $foreground !== 0 ? Color::fromValue($foreground) : null,
            background: $background !== 0 ? Color::fromValue($background) : null,
            attributes: $attributes,
        ));
    }
}
