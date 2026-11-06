<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Wrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ATableCellOfSeveralSpansTest extends TestCase
{
    #[Test]
    public function eachSpanOfACellKeepsItsOwnColour(): void
    {
        $theme = Theme::dark();
        $screen = self::drawn([Row::new('build'), Row::new('acme', self::versionWith('⚠'))]);
        ['row' => $row, 'column' => $column] = $screen->findOrFail('7.4.0 ⚠');

        self::assertSame($theme->base->foregroundValue(), $screen->styleAt($column, $row)->foreground?->value);
        self::assertSame($theme->error->foregroundValue(), $screen->styleAt($column + 6, $row)->foreground?->value);
    }

    #[Test]
    public function aCutCellKeepsItsColoursAndEndsInTheColourOfTheSpanItCuts(): void
    {
        $theme = Theme::dark();
        $installed = Column::new('Installed', Constraint::exact(9))->withWrap(Wrap::NoWrap);
        $screen = self::drawn([Row::new('build'), Row::new('acme', self::versionWith('critical'))], $installed);
        ['row' => $row, 'column' => $column] = $screen->findOrFail('7.4.0 cr…');

        self::assertSame($theme->base->foregroundValue(), $screen->styleAt($column, $row)->foreground?->value);
        self::assertSame($theme->error->foregroundValue(), $screen->styleAt($column + 6, $row)->foreground?->value);
        self::assertSame($theme->error->foregroundValue(), $screen->styleAt($column + 8, $row)->foreground?->value);
    }

    #[Test]
    public function aCellCutInItsFirstSpanEndsInThatSpansColour(): void
    {
        $installed = Column::new('Installed', Constraint::exact(4))->withWrap(Wrap::NoWrap);
        $screen = self::drawn([Row::new('build'), Row::new('acme', self::versionWith('⚠'))], $installed);
        ['row' => $row, 'column' => $column] = $screen->findOrFail('7.4…');

        self::assertSame(Theme::dark()->base->foregroundValue(), $screen->styleAt($column + 3, $row)->foreground?->value);
    }

    #[Test]
    public function onTheCursorRowTheWarningKeepsItsColourLiftedOffTheBand(): void
    {
        $theme = Theme::dark();
        $screen = self::drawn([Row::new('acme', self::versionWith('⚠'))]);
        ['row' => $row, 'column' => $column] = $screen->findOrFail('7.4.0 ⚠');

        self::assertSame($theme->cursorStyle->foregroundValue(), $screen->styleAt($column, $row)->foreground?->value);
        self::assertSame($theme->cursorStyle->backgroundValue(), $screen->styleAt($column + 6, $row)->background?->value);
        self::assertSame($theme->error->readableOn($theme->cursorStyle)->foregroundValue(), $screen->styleAt($column + 6, $row)->foreground?->value);
    }

    #[Test]
    public function aCellOfSeveralSpansReadsAsItsWholeText(): void
    {
        self::assertSame('7.4.0 ⚠', Row::new('acme', self::versionWith('⚠'))->textAt(1));
    }

    #[Test]
    public function aWrappedCellKeepsEachSpansColourOnEveryLine(): void
    {
        $theme = Theme::dark();
        $screen = self::drawn([Row::new('build'), Row::new('acme', self::versionWith('high risk'))], Column::new('Installed', Constraint::exact(5)));
        ['row' => $version, 'column' => $column] = $screen->findOrFail('7.4.0');
        $risk = $screen->findOrFail('risk');

        self::assertSame($theme->base->foregroundValue(), $screen->styleAt($column, $version)->foreground?->value);
        self::assertSame($theme->error->foregroundValue(), $screen->styleAt($column, $version + 1)->foreground?->value);
        self::assertSame($theme->error->foregroundValue(), $screen->styleAt($risk['column'], $risk['row'])->foreground?->value);
    }

    private static function versionWith(string $warning): Line
    {
        return new Line([Span::raw('7.4.0 '), Span::token($warning, StyleToken::Error)]);
    }

    /**
     * @param list<Row> $rows
     */
    private static function drawn(array $rows, ?Column $installed = null): ScreenBuffer
    {
        $frame = new Frame(30, 6);
        Table::new([Column::new('Name', Constraint::exact(6)), $installed ?? Column::new('Installed', Constraint::flex())], $rows)
            ->render($frame, Rect::fromSize(30, 6), RenderContext::focused(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }
}
