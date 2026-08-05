<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Alert;
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

        self::assertSame(Color::ansi(0)->value, $onAmber, 'black on a light fill');
        self::assertSame(Color::ansi(15)->value, $onDarkRed, 'white on a dark one');
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
        $alert = Alert::warning('Low disk')->pulsing(1.0);

        self::assertSame(Color::hex('E5C07B')->value, self::fillAt($alert, 0.0));
    }

    #[Test]
    public function aPulseHasMovedTheFillByHalfItsPeriod(): void
    {
        $alert = Alert::warning('Low disk')->pulsing(1.0);

        self::assertNotSame(self::fillAt($alert, 0.0), self::fillAt($alert, 0.5));
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
        $alert = Alert::warning('Low disk')->pulsing(1.0);

        [$red, $green, $blue] = self::channelsOf(self::fillAt($alert, 0.0));
        [$pulsedRed, $pulsedGreen, $pulsedBlue] = self::channelsOf(self::fillAt($alert, 0.5));

        self::assertGreaterThan($red, $pulsedRed, 'a light fill under black text has to lighten, never close on the text');
        self::assertGreaterThan($green, $pulsedGreen);
        self::assertGreaterThan($blue, $pulsedBlue);
    }

    #[Test]
    public function aPulseHoldsStillWhereTheTerminalCannotShowIt(): void
    {
        $alert = Alert::warning('Low disk')->pulsing(1.0);

        self::assertSame(
            self::fillAt($alert, 0.0, ColorProfile::Ansi),
            self::fillAt($alert, 0.5, ColorProfile::Ansi),
            'sixteen colours quantise the fade away, so there is nothing to animate',
        );
    }

    #[Test]
    public function aPulsingAlertKeepsTheFramesComing(): void
    {
        $context = self::renderedContext(Alert::warning('Low disk')->pulsing(1.0), 0.0, ColorProfile::TrueColor);

        self::assertNotNull($context->repaintDelay());
    }

    #[Test]
    public function anAlertHoldingStillLetsTheRenderLoopSleep(): void
    {
        $still = self::renderedContext(Alert::warning('Low disk'), 0.0, ColorProfile::TrueColor);
        $flattened = self::renderedContext(Alert::warning('Low disk')->pulsing(1.0), 0.0, ColorProfile::Ansi);

        self::assertNull($still->repaintDelay(), 'nothing is moving');
        self::assertNull($flattened->repaintDelay(), 'and a fade nobody can see must not cost frames either');
    }

    /**
     * @return iterable<string, array{Alert, callable(Theme): Style}>
     */
    public static function severityToThemeStyle(): iterable
    {
        yield 'warning' => [Alert::warning('careful'), static fn (Theme $theme): Style => $theme->warning];
        yield 'error' => [Alert::error('broken'), static fn (Theme $theme): Style => $theme->error];
        yield 'success' => [Alert::success('done'), static fn (Theme $theme): Style => $theme->success];
        yield 'info' => [Alert::info('note'), static fn (Theme $theme): Style => $theme->info];
    }

    /**
     * @param callable(Theme): Style $style
     */
    #[Test]
    #[DataProvider('severityToThemeStyle')]
    public function eachSeverityFillsWithItsOwnThemeColour(Alert $alert, callable $style): void
    {
        $frame = new Frame(20, 1);
        $theme = Theme::dark();

        $alert->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

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
        $context = new RenderContext(Theme::dark(), $profile);
        $context->beginFrame($now);

        $alert->render($frame, new Rect(0, 0, 20, 1), $context);

        return $frame->backgrounds[0];
    }

    private static function renderedContext(Alert $alert, float $now, ColorProfile $profile): RenderContext
    {
        $context = new RenderContext(Theme::dark(), $profile);
        $context->beginFrame($now);

        $alert->render(new Frame(20, 1), new Rect(0, 0, 20, 1), $context);

        return $context;
    }

    /**
     * @return array{int, int, int}
     */
    private static function channelsOf(int $value): array
    {
        $rgb = $value - Color::TRUE;
        self::assertGreaterThanOrEqual(0, $rgb, 'The dark theme fills with truecolor');

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
