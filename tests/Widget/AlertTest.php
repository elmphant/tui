<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Widget\Feedback\Alert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    #[Test]
    public function theSeverityColourFillsEveryColumnOfTheRow(): void
    {
        $frame = new Frame(20, 1);
        $alert = Alert::warning('Low disk');

        $alert->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        $amber = Color::hex('E5C07B')->value;
        self::assertSame($amber, $frame->backgrounds[0], 'the first column');
        self::assertSame($amber, $frame->backgrounds[19], 'and the last, past the end of the message');
    }

    #[Test]
    public function theMessageStartsOnePaddedColumnIn(): void
    {
        $frame = new Frame(20, 1);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('Low disk', implode('', array_slice($frame->characters, 1, 8)));
    }

    #[Test]
    public function theTextContrastsWithWhateverFillItSitsOn(): void
    {
        $onAmber = self::messageForeground(Theme::dark());
        $onDarkRed = self::messageForeground(
            Theme::dark()->withWarning(new Style(foreground: Color::hex('9D0006'))),
        );

        self::assertSame(Color::ansi(AnsiColor::Black)->value, $onAmber, 'black on a light fill');
        self::assertSame(Color::ansi(AnsiColor::BrightWhite)->value, $onDarkRed, 'white on a dark one');
    }

    #[Test]
    public function anAlertAsksForExactlyOneRow(): void
    {
        self::assertEquals(Constraint::exact(1), Alert::warning('Low disk')->sizing()->rows);
    }

    #[Test]
    public function anAreaWithNoHeightIsLeftAlone(): void
    {
        $frame = new Frame(20, 1);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 20, 0), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->backgrounds[0], 'a row of no height is still no row');
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function anAlertPaintsItsOwnRowAndNoOther(): void
    {
        $frame = new Frame(20, 2);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        self::assertSame(Color::hex('E5C07B')->value, $frame->backgrounds[0], 'the row it was given');
        self::assertSame(0, $frame->backgrounds[20], 'and the one below untouched');
    }

    #[Test]
    public function aColumnTooNarrowForTextStillCarriesTheSeverityColour(): void
    {
        $frame = new Frame(20, 1);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 1, 1), new RenderContext(Theme::dark()));

        self::assertSame(Color::hex('E5C07B')->value, $frame->backgrounds[0], 'one column is still worth colouring');
        self::assertSame(' ', $frame->characters[0], 'but there is no room to say anything in it');
    }

    #[Test]
    public function nothingIsPaintedOutsideTheAreaItWasGiven(): void
    {
        $frame = new Frame(20, 1);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 1, 1), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[1], 'a one column alert must not reach into the second');
        self::assertSame(0, $frame->backgrounds[1]);
    }

    #[Test]
    public function aRowWithRoomForOneCharacterGetsOne(): void
    {
        $frame = new Frame(20, 1);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 3, 1), new RenderContext(Theme::dark()));

        self::assertSame('L', $frame->characters[1], 'padding, one character, padding');
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function aMessageTooLongForTheRowKeepsItsTrailingPad(): void
    {
        $frame = new Frame(20, 1);

        Alert::warning(str_repeat('x', 30))->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        self::assertSame(str_repeat('x', 18), implode('', array_slice($frame->characters, 1, 18)));
        self::assertSame(' ', $frame->characters[19], 'the last column stays fill, so the text never touches the edge');
    }

    #[Test]
    public function aPulseLeavesTheFillAtFullStrengthWhereItStarts(): void
    {
        $alert = Alert::warning('Low disk')->withPulsing(1.0);

        self::assertSame(Color::hex('E5C07B')->value, self::fillAt($alert, 0.0));
    }

    #[Test]
    public function aPulseHasFadedByItsFullDepthHalfWayThroughThePeriod(): void
    {
        $alert = Alert::warning('Low disk')->withPulsing(1.0);

        self::assertSame(Color::rgb(237, 211, 163)->value, self::fillAt($alert, 0.5));
    }

    #[Test]
    public function aPulseIsPartWayThereAtAQuarterOfThePeriod(): void
    {
        $alert = Alert::warning('Low disk')->withPulsing(1.0);

        self::assertSame(Color::rgb(233, 201, 143)->value, self::fillAt($alert, 0.25));
    }

    #[Test]
    public function aLongerPeriodStretchesTheSameFadeOverMoreTime(): void
    {
        $slow = Alert::warning('Low disk')->withPulsing(2.0);

        self::assertSame(
            Color::rgb(233, 201, 143)->value,
            self::fillAt($slow, 0.5),
            'half a second into a two second breath is a quarter of the way round',
        );
    }

    #[Test]
    public function anAlertThatIsNotPulsingHoldsStillAsTheClockRuns(): void
    {
        $alert = Alert::warning('Low disk');

        self::assertSame(self::fillAt($alert, 0.0), self::fillAt($alert, 0.5));
    }

    #[Test]
    public function aPulseFadesAwayFromTheTextItHasToStayReadableUnder(): void
    {
        $alert = Alert::warning('Low disk')->withPulsing(1.0);

        [$red, $green, $blue] = self::channelsOf(self::fillAt($alert, 0.0));
        [$pulsedRed, $pulsedGreen, $pulsedBlue] = self::channelsOf(self::fillAt($alert, 0.5));

        self::assertGreaterThan($red, $pulsedRed, 'a light fill under black text has to lighten, never close on the text');
        self::assertGreaterThan($green, $pulsedGreen);
        self::assertGreaterThan($blue, $pulsedBlue);
    }

    #[Test]
    public function aPulseHoldsStillWhereTheTerminalCannotShowIt(): void
    {
        $alert = Alert::warning('Low disk')->withPulsing(1.0);

        self::assertSame(
            self::fillAt($alert, 0.0, ColorProfile::Ansi),
            self::fillAt($alert, 0.5, ColorProfile::Ansi),
            'sixteen colours quantise the fade away, so there is nothing to animate',
        );
    }

    #[Test]
    public function aPulsingAlertKeepsTheFramesComing(): void
    {
        $pass = self::renderedPass(Alert::warning('Low disk')->withPulsing(1.0), 0.0, ColorProfile::TrueColor);

        self::assertSame(0.05, $pass->clock->repaintDelay(), 'twenty frames a second is enough to read as a fade');
    }

    #[Test]
    public function anAlertHoldingStillLetsTheRenderLoopSleep(): void
    {
        $still = self::renderedPass(Alert::warning('Low disk'), 0.0, ColorProfile::TrueColor);
        $flattened = self::renderedPass(Alert::warning('Low disk')->withPulsing(1.0), 0.0, ColorProfile::Ansi);

        self::assertNull($still->clock->repaintDelay(), 'nothing is moving');
        self::assertNull($flattened->clock->repaintDelay(), 'and a fade nobody can see must not cost frames either');
    }

    /**
     * @return iterable<string, array{callable():Alert, callable(Theme):Style}>
     */
    public static function severityToThemeStyle(): iterable
    {
        yield 'warning' => [
            static fn (): Alert => Alert::warning('careful'),
            static fn (Theme $theme): Style => $theme->warning,
        ];
        yield 'error' => [
            static fn (): Alert => Alert::error('broken'),
            static fn (Theme $theme): Style => $theme->error,
        ];
        yield 'success' => [
            static fn (): Alert => Alert::success('done'),
            static fn (Theme $theme): Style => $theme->success,
        ];
        yield 'info' => [
            static fn (): Alert => Alert::info('note'),
            static fn (Theme $theme): Style => $theme->info,
        ];
    }

    /**
     * @param callable():Alert       $build
     * @param callable(Theme): Style $style
     */
    #[Test]
    #[DataProvider('severityToThemeStyle')]
    public function eachSeverityFillsWithItsOwnThemeColour(callable $build, callable $style): void
    {
        $frame = new Frame(20, 1);
        $theme = Theme::dark();

        $build()->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        self::assertSame($style($theme)->foreground?->value, $frame->backgrounds[0]);
    }

    private static function messageForeground(Theme $theme): int
    {
        $frame = new Frame(20, 1);

        Alert::warning('Low disk')->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        return $frame->foregrounds[1];
    }

    private static function fillAt(Alert $alert, float $now, ColorProfile $profile = ColorProfile::TrueColor): int
    {
        $frame = new Frame(20, 1);
        $pass = RenderPass::new(Theme::dark(), $profile);
        $pass->begin($now);

        $alert->render($frame, new Rect(0, 0, 20, 1), $pass->context);

        return $frame->backgrounds[0];
    }

    private static function renderedPass(Alert $alert, float $now, ColorProfile $profile): RenderPass
    {
        $pass = RenderPass::new(Theme::dark(), $profile);
        $pass->begin($now);

        $alert->render(new Frame(20, 1), new Rect(0, 0, 20, 1), $pass->context);

        return $pass;
    }

    /**
     * @return array{int, int, int}
     */
    private static function channelsOf(int $value): array
    {
        $rgb = $value - Color::TRUE_COLOR;
        self::assertGreaterThanOrEqual(0, $rgb, 'The dark theme fills with truecolor');

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
