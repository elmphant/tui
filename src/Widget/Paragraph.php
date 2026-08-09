<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Grapheme;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\SgrParser;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\SpanRenderer;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\WrapCache;

final readonly class Paragraph implements Widget, MeasureHeight, MeasureWidth
{
    use Cloneable;

    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public array $lines = [],
        public Alignment $alignment = Alignment::Left,
        public Wrap $wrap = Wrap::WordWrap,
        public ?Style $style = null,
        public ?Constraint $constraint = null,
    ) {
    }

    /**
     * @param list<Line> $lines
     */
    public function withLines(array $lines): self
    {
        return $this->cloneWith(lines: $lines);
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

    public function withConstraint(?Constraint $constraint): self
    {
        return $this->cloneWith(constraint: $constraint);
    }

    public static function fromText(string $text): self
    {
        $lines = array_map(
            static fn (string $raw) => Line::raw($raw),
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
            $currentForeground = $parser->foreground;
            $currentBackground = $parser->background;
            $currentAttributes = $parser->attributes;
            $length = strlen($rawLine);
            $position = 0;

            while ($position < $length) {
                $byte = ord($rawLine[$position]);

                if ($byte === 0x1B && $position + 1 < $length && $rawLine[$position + 1] === '[') {
                    $end = strpos($rawLine, 'm', $position + 2);
                    if ($end !== false) {
                        if ($currentText !== '') {
                            $spans[] = self::spanFromSgrState($currentText, $currentForeground, $currentBackground, $currentAttributes);
                            $currentText = '';
                        }
                        $parser->parse(substr($rawLine, $position + 2, $end - $position - 2));
                        $currentForeground = $parser->foreground;
                        $currentBackground = $parser->background;
                        $currentAttributes = $parser->attributes;
                        $position = $end + 1;
                        continue;
                    }
                }

                $characterByteLength = Grapheme::sequenceLength($byte);
                $currentText .= substr($rawLine, $position, $characterByteLength);
                $position += $characterByteLength;
            }

            if ($currentText !== '') {
                $spans[] = self::spanFromSgrState($currentText, $currentForeground, $currentBackground, $currentAttributes);
            }

            $lines[] = new Line($spans);
        }

        return new self($lines);
    }

    public function constraint(): Constraint
    {
        return $this->constraint ?? Constraint::flex();
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        if ($this->wrap === Wrap::NoWrap) {
            return count($this->lines);
        }

        $rows = 0;

        foreach ($this->lines as $line) {
            $rows += $this->rowsOfLine($line, $width);
        }

        return $rows;
    }

    public function measureWidth(): int
    {
        $width = 0;
        foreach ($this->lines as $line) {
            $width = max($width, $line->width());
        }

        return $width;
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

        $lines = array_map(
            static fn (Line $line): Line => $line->withResolvedTokens($context->theme),
            array_slice($this->lines, $firstLine, $visible->height),
        );

        $visualLines = $this->visualLines($area->width, $baseStyle, $lines, $dropped + $visible->height);
        $visualLines = array_slice($visualLines, $dropped);

        $row = $skipped;
        foreach ($visualLines as $visualLine) {
            if ($row >= $skipped + $visible->height) {
                break;
            }

            $lineWidth = $this->visualLineWidth($visualLine);
            $offsetX = $this->alignmentOffset($lineWidth, $area->width);

            $x = $area->x + $offsetX;
            foreach ($visualLine as [$character, $style]) {
                if ($x >= $area->x + $area->width) {
                    break;
                }
                $characterWidth = CharWidth::of($character);
                if ($characterWidth > 0) {
                    $frame->putChar(
                        $x,
                        $area->y + $row,
                        $character,
                        $style->foregroundValue(),
                        $style->backgroundValue(),
                        $style->attributes,
                    );
                    $x += $characterWidth;
                }
            }

            ++$row;
        }
    }

    /**
     * Which source line owns visual row $target, and how many rows precede it.
     *
     * Walks only as far as the band being drawn, so an unscrolled paragraph
     * settles immediately.
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

        $rows = 0;
        foreach ($this->lines as $index => $line) {
            $height = $this->rowsOfLine($line, $width);

            if ($rows + $height > $target) {
                return [$index, $rows];
            }

            $rows += $height;
        }

        return [count($this->lines), $rows];
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
     * A row is an offset and a length into the line's characters. Rows do not
     * always tile: a break at a space drops that space, but a space that is
     * itself too wide for the row starts the next one instead.
     *
     * @return list<array{int, int}>
     */
    private function wrappedRows(Line $line, int $width): array
    {
        $text = '';
        foreach ($line->spans as $span) {
            $text .= $span->content;
        }

        /** @var list<array{int, int}> */
        return WrapCache::remember(
            $width . ':' . hash('xxh3', $text),
            static fn (): array => self::rowBounds(mb_str_split($text), $width),
        );
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
            $characterWidth = CharWidth::of($character);

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
                    $rowStart += $character === ' ' ? $lastSpaceOffset : $lastSpaceOffset + 1;
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

        return $rows;
    }

    /**
     * @param list<Line> $lines
     *
     * @return list<list<array{string, Style}>>
     */
    private function visualLines(int $width, Style $baseStyle, array $lines, ?int $limit = null): array
    {
        return $this->wrap === Wrap::WordWrap
            ? $this->wrapLines($width, $baseStyle, $lines, $limit)
            : $this->noWrapLines($baseStyle, $lines, $limit);
    }

    /**
     * @param list<Line> $lines
     *
     * @return list<list<array{string, Style}>>
     */
    private function noWrapLines(Style $baseStyle, array $lines, ?int $limit = null): array
    {
        $result = [];
        foreach ($lines as $line) {
            $result[] = $this->decomposeLineToGraphemes($line, $baseStyle);

            if ($limit !== null && count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param list<Line> $lines
     *
     * @return list<list<array{string, Style}>>
     */
    private function wrapLines(int $maxWidth, Style $baseStyle, array $lines, ?int $limit = null): array
    {
        $result = [];
        foreach ($lines as $line) {
            $graphemes = $this->decomposeLineToGraphemes($line, $baseStyle);
            $rows = $line->width() <= $maxWidth
                ? [[0, count($graphemes)]]
                : $this->wrappedRows($line, $maxWidth);

            foreach ($rows as [$start, $length]) {
                $result[] = array_slice($graphemes, $start, $length);
            }

            if ($limit !== null && count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<array{string, Style}>
     */
    private function decomposeLineToGraphemes(Line $line, Style $baseStyle): array
    {
        $lineStyle = $line->style !== null
            ? $line->style->inherit($baseStyle)
            : $baseStyle;

        $graphemes = [];
        foreach ($line->spans as $span) {
            $spanStyle = $span->style !== null
                ? $span->style->inherit($lineStyle)
                : $lineStyle;

            $characters = mb_str_split($span->content);
            foreach ($characters as $character) {
                $graphemes[] = [$character, $spanStyle];
            }
        }

        return $graphemes;
    }

    /**
     * @param list<array{string, Style}> $visualLine
     */
    private function visualLineWidth(array $visualLine): int
    {
        $width = 0;
        foreach ($visualLine as [$character]) {
            $width += CharWidth::of($character);
        }

        return $width;
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
