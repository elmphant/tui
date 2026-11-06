<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\FrameClock;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APulsingTableRowTest extends TestCase
{
    private const WIDTH = 30;
    private const PULSING_CELL = 2 * self::WIDTH + 1;

    #[Test]
    public function aPulsingRowIsDrawnOnABandOfTheWarningColour(): void
    {
        $theme = Theme::dark();

        $frame = self::drawn(self::table(), 0.0, ColorProfile::Ansi)[0];

        $band = $theme->warning->foreground ?? Color::none();
        self::assertSame($band->value, $frame->backgrounds[self::PULSING_CELL]);
        self::assertSame($band->contrastingText()->value, $frame->foregrounds[self::PULSING_CELL]);
        self::assertSame(0, $frame->backgrounds[self::WIDTH + 1], 'the row above stays plain');
    }

    #[Test]
    public function theBandFadesAndKeepsTheFramesComingWhereTheTerminalBlendsColours(): void
    {
        [$atRest, $clock] = self::drawn(self::table(), 0.0, ColorProfile::TrueColor);
        [$halfway] = self::drawn(self::table(), 0.6, ColorProfile::TrueColor);

        self::assertNotSame($atRest->backgrounds[self::PULSING_CELL], $halfway->backgrounds[self::PULSING_CELL]);
        self::assertSame(0.05, $clock->repaintDelay());
    }

    #[Test]
    public function theBandHoldsStillAndCostsNoFramesInSixteenColours(): void
    {
        [$atRest, $clock] = self::drawn(self::table(), 0.0, ColorProfile::Ansi);
        [$halfway] = self::drawn(self::table(), 0.6, ColorProfile::Ansi);

        self::assertSame($atRest->backgrounds[self::PULSING_CELL], $halfway->backgrounds[self::PULSING_CELL]);
        self::assertNull($clock->repaintDelay());
    }

    #[Test]
    public function aTableWithNothingPulsingCostsNoFrames(): void
    {
        $table = Table::new([Column::new('Job', Constraint::flex())], [Row::new('build')]);

        self::assertNull(self::drawn($table, 0.0, ColorProfile::TrueColor)[1]->repaintDelay());
    }

    #[Test]
    public function underTheCursorThePulseShiftsTheCursorFillAndTheTextStaysReadable(): void
    {
        $theme = Theme::dark();
        $table = self::table()->withCursor(1);

        [$atRest] = self::drawn($table, 0.0, ColorProfile::TrueColor, focused: true);
        [$halfway] = self::drawn($table, 0.6, ColorProfile::TrueColor, focused: true);

        self::assertSame($theme->cursorStyle->backgroundValue(), $atRest->backgrounds[self::PULSING_CELL], 'the cursor is still where the reader is');
        self::assertNotSame($atRest->backgrounds[self::PULSING_CELL], $halfway->backgrounds[self::PULSING_CELL]);
        self::assertGreaterThanOrEqual(
            4.5,
            Color::fromValue($halfway->foregrounds[self::PULSING_CELL])->contrastRatio(Color::fromValue($halfway->backgrounds[self::PULSING_CELL])),
        );
    }

    #[Test]
    public function underTheCursorInSixteenColoursThePulsingRowIsUnderlined(): void
    {
        [$pulsing] = self::drawn(self::table()->withCursor(1), 0.0, ColorProfile::Ansi, focused: true);
        [$plain] = self::drawn(self::table()->withCursor(0), 0.0, ColorProfile::Ansi, focused: true);

        self::assertSame(Attr::UNDERLINE, $pulsing->attributes[self::PULSING_CELL] & Attr::UNDERLINE);
        self::assertSame(0, $plain->attributes[self::WIDTH + 1] & Attr::UNDERLINE, 'a cursor row that is not pulsing');
    }

    #[Test]
    public function aPulsingRowKeepsItsBandThroughAFilterThatKeepsIt(): void
    {
        $theme = Theme::dark();
        $filtered = FilterableTable::new([Column::new('Job', Constraint::flex())], [Row::new('build'), Row::new('deploy')->withPulsing()])
            ->withQuery('dep');
        $frame = new Frame(self::WIDTH, 6);

        $filtered->render($frame, Rect::fromSize(self::WIDTH, 6), new RenderContext($theme, ColorProfile::Ansi, focus: FocusPath::nowhere()));

        $row = ScreenBuffer::fromFrame($frame)->findOrFail('deploy')['row'];
        self::assertSame($theme->warning->foreground?->value, $frame->backgrounds[$row * self::WIDTH + 1]);
    }

    private static function table(): Table
    {
        return Table::new(
            [Column::new('Job', Constraint::flex())],
            [Row::new('build'), Row::new('deploy')->withPulsing()],
        );
    }

    /**
     * @return array{Frame, FrameClock}
     */
    private static function drawn(Table $table, float $now, ColorProfile $profile, bool $focused = false): array
    {
        $frame = new Frame(self::WIDTH, 4);
        $clock = new FrameClock();
        $clock->begin($now);

        $table->render(
            $frame,
            Rect::fromSize(self::WIDTH, 4),
            new RenderContext(Theme::dark(), $profile, $clock, $focused ? FocusPath::here() : FocusPath::nowhere()),
        );

        return [$frame, $clock];
    }
}
