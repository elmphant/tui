<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A reversed row leaves the two colours to the terminal, so nothing can measure
 * what the reader ends up looking at. The cursor row is drawn in the highlight
 * the theme already had measured for it, the same one a list uses.
 */
final class TableCursorHighlightTest extends TestCase
{
    #[Test]
    public function theCursorRowIsFilledWithTheHighlightTheThemeMeasured(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 4);

        self::tableOf()->withFocus(true)->render($frame, Rect::fromSize(30, 4), new RenderContext($theme));

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
            [Row::new(Span::styled('Alice', new Style(foreground: Color::ansi(2))))],
        )->withFocus(true)->render($frame, Rect::fromSize(30, 3), new RenderContext($theme));

        $ink = Color::fromValue($frame->foregrounds[30]);
        $band = Color::fromValue($frame->backgrounds[30]);

        self::assertGreaterThanOrEqual(
            4.5,
            $ink->contrastRatio($band),
            'a reversed row could not be measured at all, which is the point of the change',
        );
    }

    private static function tableOf(): Table\Table
    {
        return Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(4)), Column::new('Task', Constraint::flex())],
            [['1', 'first'], ['2', 'second']],
        );
    }
}
