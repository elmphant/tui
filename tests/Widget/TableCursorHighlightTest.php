<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Use the theme's measured highlight colours; reverse video leaves contrast to an unknown terminal palette.
 */
final class TableCursorHighlightTest extends TestCase
{
    #[Test]
    public function theCursorRowIsFilledWithTheHighlightTheThemeMeasured(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 4);

        self::tableOf()->render($frame, Rect::fromSize(30, 4), RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[30]);
        self::assertSame(0, $frame->attributes[30] & Attr::REVERSE);
    }

    #[Test]
    public function aCellWithItsOwnColourStaysReadableOnTheHighlight(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 3);

        Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new(Span::styled('Alice', new Style(foreground: Color::ansi(AnsiColor::Green))))],
        )->render($frame, Rect::fromSize(30, 3), RenderContext::focused($theme));

        $ink = Color::fromValue($frame->foregrounds[30]);
        $band = Color::fromValue($frame->backgrounds[30]);

        self::assertGreaterThanOrEqual(
            4.5,
            $ink->contrastRatio($band),
            'a reversed row could not be measured at all, which is the point of the change',
        );
    }

    #[Test]
    public function withoutColourTheCursorRowIsDrawnInReverseVideo(): void
    {
        $frame = new Frame(30, 4);

        Table\Table::new(
            [Column::new('ID', Constraint::exact(4)), Column::new('Name', Constraint::flex())],
            [Row::new('1', Span::styled('Alice', new Style(foreground: Color::ansi(AnsiColor::Green)))), Row::new('2', 'Bob')],
        )->render($frame, Rect::fromSize(30, 4), RenderContext::focused(Theme::dark(), ColorProfile::Ascii));

        $cursorRow = array_slice($frame->attributes, 30, 30);
        $otherRow = array_slice($frame->attributes, 60, 30);

        self::assertSame([], array_filter($cursorRow, static fn (int $attributes): bool => 0 === ($attributes & Attr::REVERSE)));
        self::assertSame([], array_filter($otherRow, static fn (int $attributes): bool => 0 !== ($attributes & Attr::REVERSE)));
    }

    private static function tableOf(): Table\Table
    {
        return Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(4)), Column::new('Task', Constraint::flex())],
            [['1', 'first'], ['2', 'second']],
        );
    }
}
