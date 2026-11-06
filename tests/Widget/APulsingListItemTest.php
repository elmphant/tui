<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\FrameClock;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APulsingListItemTest extends TestCase
{
    private const WIDTH = 20;
    private const PULSING_CELL = self::WIDTH + 1;

    #[Test]
    public function aPulsingItemIsDrawnOnABandOfTheWarningColour(): void
    {
        $theme = Theme::dark();

        [$frame] = self::drawn(self::list(), 0.0, ColorProfile::Ansi);
        [$plain] = self::drawn(ListView::new([new SimpleListItem('build'), new SimpleListItem('deploy')]), 0.0, ColorProfile::Ansi);

        self::assertSame($theme->warning->foreground?->value, $frame->backgrounds[self::PULSING_CELL]);
        self::assertSame(0, $plain->backgrounds[self::PULSING_CELL], 'the same item not pulsing');
    }

    #[Test]
    public function theBandFadesAndKeepsTheFramesComingWhereTheTerminalBlendsColours(): void
    {
        [$atRest, $clock] = self::drawn(self::list(), 0.0, ColorProfile::TrueColor);
        [$halfway] = self::drawn(self::list(), 0.6, ColorProfile::TrueColor);

        self::assertNotSame($atRest->backgrounds[self::PULSING_CELL], $halfway->backgrounds[self::PULSING_CELL]);
        self::assertSame(0.05, $clock->repaintDelay());
    }

    #[Test]
    public function underTheCursorInSixteenColoursThePulsingItemIsUnderlined(): void
    {
        [$frame] = self::drawn(self::list()->withCursor(1), 0.0, ColorProfile::Ansi, focused: true);

        self::assertSame(Attr::UNDERLINE, $frame->attributes[self::PULSING_CELL] & Attr::UNDERLINE);
    }

    private static function list(): ListView
    {
        return ListView::new([new SimpleListItem('build'), (new SimpleListItem('deploy'))->withPulsing()]);
    }

    /**
     * @return array{Frame, FrameClock}
     */
    private static function drawn(ListView $list, float $now, ColorProfile $profile, bool $focused = false): array
    {
        $frame = new Frame(self::WIDTH, 3);
        $clock = new FrameClock();
        $clock->begin($now);

        $list->render(
            $frame,
            Rect::fromSize(self::WIDTH, 3),
            new RenderContext(Theme::dark(), $profile, $clock, $focused ? FocusPath::here() : FocusPath::nowhere()),
        );

        return [$frame, $clock];
    }
}
