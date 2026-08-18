<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineTest extends TestCase
{
    #[Test]
    public function fromSpansCreatesLineWithSpans(): void
    {
        $line = new Line([new Span('hello'), new Span('world')]);

        self::assertCount(2, $line->spans);
        self::assertSame('hello', $line->spans[0]->content);
    }

    #[Test]
    public function rawCreatesLineWithSinglePlainSpan(): void
    {
        $line = Line::raw('hello world');

        self::assertCount(1, $line->spans);
        self::assertSame('hello world', $line->spans[0]->content);
        self::assertNull($line->spans[0]->style);
    }

    #[Test]
    public function widthSumsSpanWidths(): void
    {
        $line = new Line([new Span('AB'), new Span('CD')]);

        self::assertSame(4, $line->width());
    }

    #[Test]
    public function widthHandlesWideCharacters(): void
    {
        $line = new Line([new Span('A'), new Span('你好')]);

        self::assertSame(5, $line->width());
    }

    #[Test]
    public function styleIsNullByDefault(): void
    {
        $line = new Line([new Span('test')]);

        self::assertNull($line->style);
    }

    #[Test]
    public function styleCanBeSetViaConstructor(): void
    {
        $style = new Style(foreground: Color::ansi(1));
        $line = new Line([new Span('test')], $style);

        self::assertSame($style, $line->style);
    }

    #[Test]
    public function emptyLineHasZeroWidth(): void
    {
        $line = new Line([]);

        self::assertSame(0, $line->width());
    }

    #[Test]
    public function aLineOnAPanelLiftsEverySpanItHolds(): void
    {
        $panel = new Style(background: Color::hex('3E4451'));
        $line = new Line([
            Span::styled('$total', new Style(foreground: Color::hex('E06C75'))),
            Span::styled(' // running', new Style(foreground: Color::hex('5C6370'))),
        ]);

        $placed = $line->readableOn($panel);

        foreach ($placed->spans as $index => $span) {
            self::assertNotNull($span->style?->foreground, "span {$index} lost its ink");
            self::assertGreaterThanOrEqual(4.5, $span->style->foreground->contrastRatio(Color::hex('3E4451')));
        }
    }
}
