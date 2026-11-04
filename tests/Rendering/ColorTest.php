<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Terminal\ColorProfile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    #[Test]
    public function noneHasZeroValue(): void
    {
        self::assertSame(0, Color::none()->value);
    }

    #[Test]
    public function ansiEncodesExactValue(): void
    {
        self::assertSame(Color::ANSI + 0, Color::ansi(AnsiColor::Black)->value);
        self::assertSame(Color::ANSI + 1, Color::ansi(AnsiColor::Red)->value);
        self::assertSame(Color::ANSI + 7, Color::ansi(AnsiColor::White)->value);
    }

    #[Test]
    public function ansi15IsTreatedAsBright(): void
    {
        $color = Color::ansi(AnsiColor::BrightWhite);
        self::assertSame(Color::BRIGHT + 7, $color->value);
        self::assertSame('97', $color->toForegroundSgr());
    }

    #[Test]
    public function hexColorParsesLeadingHash(): void
    {
        $color = Color::hex('#FF0000');
        self::assertSame(Color::rgb(255, 0, 0)->value, $color->value);
        self::assertSame('38;2;255;0;0', $color->toForegroundSgr());
    }

    #[Test]
    public function hexParsesExactRgbValues(): void
    {
        $color = Color::hex('0A141E');
        self::assertSame(Color::rgb(10, 20, 30)->value, $color->value);
    }

    #[Test]
    public function hexExtractsRgbChannelsWithBitwiseMask(): void
    {
        $color = Color::hex('FF8040');
        self::assertSame('38;2;255;128;64', $color->toForegroundSgr());

        $colorOdd = Color::hex('010305');
        self::assertSame('38;2;1;3;5', $colorOdd->toForegroundSgr());
    }

    #[Test]
    public function hexWithoutHash(): void
    {
        $color = Color::hex('FF06B7');
        self::assertSame(Color::rgb(255, 6, 183)->value, $color->value);
    }

    #[Test]
    public function rgbMatchesHex(): void
    {
        $hex = Color::hex('FF06B7');
        $rgb = Color::rgb(255, 6, 183);
        self::assertSame($hex->value, $rgb->value);
    }

    #[Test]
    public function anAnsiColourWritesItsForegroundCode(): void
    {
        self::assertSame('31', Color::ansi(AnsiColor::Red)->toForegroundSgr());
        self::assertSame('91', Color::ansi(AnsiColor::BrightRed)->toForegroundSgr());
        self::assertSame('97', Color::ansi(AnsiColor::BrightWhite)->toForegroundSgr());
    }

    #[Test]
    public function anAnsiColourWritesItsBackgroundCode(): void
    {
        self::assertSame('41', Color::ansi(AnsiColor::Red)->toBackgroundSgr());
    }

    #[Test]
    public function aTruecolourWritesEachOfItsChannels(): void
    {
        self::assertSame('38;2;255;6;183', Color::rgb(255, 6, 183)->toForegroundSgr());
        self::assertSame('48;2;255;6;183', Color::rgb(255, 6, 183)->toBackgroundSgr());
        self::assertSame('38;2;1;1;1', Color::rgb(1, 1, 1)->toForegroundSgr());
        self::assertSame('38;2;255;255;255', Color::rgb(255, 255, 255)->toForegroundSgr());
    }

    /**
     * A channel is packed into eight bits below the truecolor tag, so one that
     * does not fit rewrites the tag and the colour comes back as another kind
     * of colour entirely.
     */
    #[Test]
    public function aChannelOutsideItsRangeIsRefusedRatherThanPackedOverTheTag(): void
    {
        $tooLarge = null;
        $negative = null;

        try {
            Color::rgb(300, 0, 0);
        } catch (InvalidArgumentException $refused) {
            $tooLarge = $refused;
        }

        try {
            Color::rgb(0, -1, 0);
        } catch (InvalidArgumentException $refused) {
            $negative = $refused;
        }

        self::assertNotNull($tooLarge, 'A red channel of 300 must be refused');
        self::assertNotNull($negative, 'A green channel of -1 must be refused');
    }

    #[Test]
    public function aPaletteIndexOutsideItsRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Color::color256(999);
    }

    /**
     * hexdec() takes the digits it recognises and raises a deprecation about the
     * rest, and that goes to the display the TUI is drawing on.
     */
    #[Test]
    public function textThatIsNotAColourIsRefusedInsteadOfPartlyRead(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Color::hex('not-a-color');
    }

    #[Test]
    public function sixCharactersThatAreNotHexDigitsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Color::hex('zzzzzz');
    }

    #[Test]
    public function aThreeDigitHexReadsAsTheSixDigitColourItAbbreviates(): void
    {
        self::assertSame(Color::hex('FFAA00')->value, Color::hex('#FA0')->value);
    }

    #[Test]
    public function anExtendedColourLeadsWith38OrWith48(): void
    {
        self::assertSame('38;5;200', Color::color256(200)->toForegroundSgr());
        self::assertSame('48;5;200', Color::color256(200)->toBackgroundSgr());
        self::assertSame('38;2;100;50;25', Color::rgb(100, 50, 25)->toForegroundSgr());
        self::assertSame('48;2;100;50;25', Color::rgb(100, 50, 25)->toBackgroundSgr());
    }

    #[Test]
    public function noneToSgrReturnsDefault(): void
    {
        self::assertSame('39', Color::none()->toForegroundSgr());
        self::assertSame('49', Color::none()->toBackgroundSgr());
    }

    #[Test]
    public function brightAnsiSgrOutput(): void
    {
        self::assertSame('91', Color::ansi(AnsiColor::BrightRed)->toForegroundSgr());
        self::assertSame('101', Color::ansi(AnsiColor::BrightRed)->toBackgroundSgr());
    }

    #[Test]
    public function ansi8IsBrightBoundary(): void
    {
        $color = Color::ansi(AnsiColor::BrightBlack);
        self::assertSame(Color::BRIGHT, $color->value);
        self::assertSame('90', $color->toForegroundSgr());
    }

    #[Test]
    public function brightAnsiRange(): void
    {
        for ($i = 8; $i <= 15; ++$i) {
            $color = Color::ansi(AnsiColor::from($i));
            self::assertSame(Color::BRIGHT + ($i - 8), $color->value);
        }
    }

    #[Test]
    public function color256ZeroBoundary(): void
    {
        $color = Color::color256(0);
        self::assertSame(Color::COLOR_256, $color->value);
        self::assertSame('38;5;0', $color->toForegroundSgr());
        self::assertSame('48;5;0', $color->toBackgroundSgr());
    }

    #[Test]
    public function color256FullRange(): void
    {
        for ($i = 0; $i < 256; ++$i) {
            $color = Color::color256($i);
            self::assertSame(Color::COLOR_256 + $i, $color->value);
        }
    }

    #[Test]
    public function rgbBlackBoundaryToSgr(): void
    {
        $black = Color::rgb(0, 0, 0);
        self::assertSame(Color::TRUE_COLOR, $black->value);
        self::assertSame('38;2;0;0;0', $black->toForegroundSgr());
        self::assertSame('48;2;0;0;0', $black->toBackgroundSgr());
    }

    #[Test]
    public function rgbWhiteToSgr(): void
    {
        $white = Color::rgb(255, 255, 255);
        self::assertSame(Color::TRUE_COLOR + 0xFFFFFF, $white->value);
        self::assertSame('38;2;255;255;255', $white->toForegroundSgr());
    }

    #[Test]
    public function ansiIndex0IsValid(): void
    {
        $color = Color::ansi(AnsiColor::Black);
        self::assertSame(Color::ANSI, $color->value);
    }

    #[Test]
    public function ansiIndex15IsValid(): void
    {
        $color = Color::ansi(AnsiColor::BrightWhite);
        self::assertSame(Color::BRIGHT + 7, $color->value);
    }

    #[Test]
    public function fromValueRoundTrip(): void
    {
        $ansi = Color::ansi(AnsiColor::Yellow);
        self::assertSame($ansi->value, Color::fromValue($ansi->value)->value);
        self::assertSame($ansi->toForegroundSgr(), Color::fromValue($ansi->value)->toForegroundSgr());

        $bright = Color::ansi(AnsiColor::BrightGreen);
        self::assertSame($bright->toBackgroundSgr(), Color::fromValue($bright->value)->toBackgroundSgr());

        $c256 = Color::color256(42);
        self::assertSame($c256->toForegroundSgr(), Color::fromValue($c256->value)->toForegroundSgr());

        $trueColor = Color::rgb(255, 128, 0);
        self::assertSame($trueColor->toForegroundSgr(), Color::fromValue($trueColor->value)->toForegroundSgr());

        $none = Color::none();
        self::assertSame($none->toForegroundSgr(), Color::fromValue($none->value)->toForegroundSgr());
        self::assertSame($none->toBackgroundSgr(), Color::fromValue($none->value)->toBackgroundSgr());
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function rgbTo256Cases(): iterable
    {
        yield 'pure red hits cube corner' => [255, 0, 0, 196];
        yield 'pure green hits cube corner' => [0, 255, 0, 46];
        yield 'pure blue hits cube corner' => [0, 0, 255, 21];
        yield 'white hits cube corner 231' => [255, 255, 255, 231];
        yield 'black hits cube corner 16' => [0, 0, 0, 16];
        yield 'mid gray uses gray ramp' => [128, 128, 128, 244];
        yield 'near-black gray clamps to first gray step' => [10, 10, 10, 232];
        yield 'near-white gray clamps to last gray step' => [246, 246, 246, 255];
        yield 'orange maps into the cube' => [255, 135, 0, 208];
        yield 'red 95 hits the second cube level (shift boundary)' => [95, 0, 0, 52];
        yield 'green 95 hits the second cube level (shift boundary)' => [0, 95, 0, 22];
        yield 'channel 115 tie between cube levels resolves to the lower level' => [115, 0, 0, 52];
        yield 'gray/cube distance tie resolves to the cube' => [4, 4, 4, 16];
        yield 'gray 13 rounds up to the second gray step' => [13, 13, 13, 233];
        yield 'gray 12 rounds down to the first gray step' => [12, 12, 12, 232];
        yield 'off-gray narrowly beats the nearest cube corner' => [95, 95, 101, 241];
    }

    #[Test]
    #[DataProvider('rgbTo256Cases')]
    public function downsampleTruecolorToAnsi256(int $red, int $green, int $blue, int $expectedIndex): void
    {
        $downsampled = Color::rgb($red, $green, $blue)->downsampleTo(ColorProfile::Ansi256);
        self::assertSame(Color::color256($expectedIndex)->value, $downsampled->value);
    }

    /**
     * @return iterable<string, array{Color, int}>
     */
    public static function toAnsi16Cases(): iterable
    {
        yield 'bright red rgb' => [Color::rgb(255, 0, 0), 9];
        yield 'dark red rgb' => [Color::rgb(200, 0, 0), 1];
        yield 'black rgb' => [Color::rgb(0, 0, 0), 0];
        yield 'white rgb' => [Color::rgb(255, 255, 255), 15];
        yield 'mid gray rgb' => [Color::rgb(128, 128, 128), 8];
        yield 'bright yellow rgb' => [Color::rgb(255, 255, 0), 11];
        yield 'bright blue rgb' => [Color::rgb(80, 80, 255), 12];
        yield 'cyan rgb' => [Color::rgb(0, 205, 205), 6];
        yield '256 bright red' => [Color::color256(196), 9];
        yield '256 mid gray' => [Color::color256(244), 8];
        yield '256 palette passthrough of index 3' => [Color::color256(3), 3];
        yield '256 bright palette passthrough of index 12' => [Color::color256(12), 12];
        yield '256 index 0 (exact C256 boundary)' => [Color::color256(0), 0];
        yield '256 mid-cube pastel keeps its hue instead of reducing to gray' => [Color::color256(110), 4];
        yield '256 cube 87 decodes to pale cyan' => [Color::color256(87), 14];
        yield '256 first gray step (232 boundary) is near-black' => [Color::color256(232), 0];
        yield '256 cube origin (index 16) is black' => [Color::color256(16), 0];
        yield '256 gray 237 sits below the black/gray boundary' => [Color::color256(237), 0];
        yield '256 gray 238 sits above the black/gray boundary' => [Color::color256(238), 8];
        yield '256 gray 249 ties silver vs gray and picks the first' => [Color::color256(249), 7];
        yield 'rgb equidistant between dark and bright red picks the first' => [Color::rgb(230, 0, 0), 1];
        yield 'pastel red stays red' => [Color::rgb(255, 107, 107), 9];
        yield 'pastel green stays green' => [Color::rgb(143, 203, 95), 2];
        yield 'pastel blue stays blue' => [Color::rgb(95, 135, 175), 4];
        yield 'a chroma of 24 still counts as grey' => [Color::rgb(124, 100, 100), 8];
        yield 'hue 30 starts the yellow sector' => [Color::rgb(200, 100, 0), 3];
        yield 'hue 90 starts the green sector' => [Color::rgb(100, 200, 0), 2];
        yield 'hue 150 starts the cyan sector' => [Color::rgb(0, 200, 100), 6];
        yield 'hue 270 starts the magenta sector' => [Color::rgb(100, 0, 200), 5];
        yield 'hue 330 wraps back into the red sector' => [Color::rgb(200, 0, 100), 1];
    }

    /**
     * @return iterable<string, array{int, array{int, int, int}}>
     */
    public static function xtermPaletteEntries(): iterable
    {
        yield 'the first grey step' => [232, [8, 8, 8]];
        yield 'a mid grey step' => [244, [128, 128, 128]];
        yield 'the last grey step' => [255, [238, 238, 238]];
        yield 'a cube entry with only green' => [22, [0, 95, 0]];
        yield 'a cube entry with all three channels' => [110, [135, 175, 215]];
    }

    #[Test]
    #[DataProvider('xtermPaletteEntries')]
    public function aPaletteIndexResolvesToTheXtermColourItNames(int $index, array $channels): void
    {
        self::assertSame(
            Color::rgb(...$channels)->value,
            Color::color256($index)->blend(Color::rgb(0, 0, 0), 0.0)->value,
        );
    }

    #[Test]
    #[DataProvider('toAnsi16Cases')]
    public function downsampleToAnsiReducesTo16Colors(Color $color, int $expectedAnsiIndex): void
    {
        self::assertSame(Color::ansi(AnsiColor::from($expectedAnsiIndex))->value, $color->downsampleTo(ColorProfile::Ansi)->value);
    }

    #[Test]
    public function downsampleToAsciiStripsAllColor(): void
    {
        self::assertSame(0, Color::rgb(255, 0, 0)->downsampleTo(ColorProfile::Ascii)->value);
        self::assertSame(0, Color::color256(196)->downsampleTo(ColorProfile::Ascii)->value);
        self::assertSame(0, Color::ansi(AnsiColor::BrightRed)->downsampleTo(ColorProfile::Ascii)->value);
        self::assertSame(0, Color::none()->downsampleTo(ColorProfile::Ascii)->value);
    }

    #[Test]
    public function downsampleIdentitiesArePreserved(): void
    {
        $trueColor = Color::rgb(1, 2, 3);
        self::assertSame($trueColor->value, $trueColor->downsampleTo(ColorProfile::TrueColor)->value, 'TrueColor profile keeps everything');

        $indexed = Color::color256(123);
        self::assertSame($indexed->value, $indexed->downsampleTo(ColorProfile::Ansi256)->value, '256-color values survive an Ansi256 profile');

        $basic = Color::ansi(AnsiColor::Magenta);
        self::assertSame($basic->value, $basic->downsampleTo(ColorProfile::Ansi)->value, '16-color values survive an Ansi profile');

        self::assertSame(0, Color::none()->downsampleTo(ColorProfile::Ansi)->value, 'terminal default stays default');
    }

    /**
     * @return iterable<string, array{Color, int}>
     */
    public static function contrastingTextCases(): iterable
    {
        yield 'white' => [Color::hex('FFFFFF'), 0];
        yield 'black' => [Color::hex('000000'), 15];
        yield 'one dark theme amber' => [Color::hex('E5C07B'), 0];
        yield 'one light theme dark red' => [Color::hex('9D0006'), 15];
        yield 'a 256-color index' => [Color::color256(226), 0];
        yield 'basic blue' => [Color::ansi(AnsiColor::Blue), 15];
        yield 'the first bright ansi index' => [Color::ansi(AnsiColor::BrightBlack), 0];
        yield 'a grey dark enough for the linear part of the curve' => [Color::rgb(10, 10, 10), 15];
        yield 'a mid tone carried by its blue' => [Color::rgb(120, 120, 220), 0];
    }

    #[Test]
    #[DataProvider('contrastingTextCases')]
    public function contrastingTextCrossesOverWithBackgroundLuminance(Color $background, int $expectedAnsiIndex): void
    {
        self::assertSame(Color::ansi(AnsiColor::from($expectedAnsiIndex))->value, $background->contrastingText()->value);
    }

    /**
     * @return iterable<string, array{Color, float}>
     */
    public static function referenceLuminances(): iterable
    {
        yield 'white is the top of the scale' => [Color::rgb(255, 255, 255), 1.0];
        yield 'black is the bottom' => [Color::rgb(0, 0, 0), 0.0];
        yield 'red carries its own weight' => [Color::rgb(255, 0, 0), 0.2126];
        yield 'green carries most of the light' => [Color::rgb(0, 255, 0), 0.7152];
        yield 'blue carries least' => [Color::rgb(0, 0, 255), 0.0722];
        yield 'the last channel on the straight part of the curve' => [Color::rgb(10, 10, 10), 0.00303526983548837];
        yield 'the first channel past the bend' => [Color::rgb(11, 11, 11), 0.00334653576389916];
    }

    #[Test]
    #[DataProvider('referenceLuminances')]
    public function luminanceFollowsTheWcagCurve(Color $color, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $color->relativeLuminance(), 1e-12);
    }

    #[Test]
    public function theTerminalDefaultHasNoKnowableContrast(): void
    {
        self::assertSame(0, Color::none()->contrastingText()->value);
    }

    #[Test]
    public function blendingWalksFromOneColourToTheOther(): void
    {
        $black = Color::hex('000000');
        $white = Color::hex('FFFFFF');

        self::assertSame($black->value, $black->blend($white, 0.0)->value, 'nothing of the other colour');
        self::assertSame($white->value, $black->blend($white, 1.0)->value, 'all of it');
        self::assertSame(Color::rgb(128, 128, 128)->value, $black->blend($white, 0.5)->value, 'and the midpoint between');
    }

    #[Test]
    public function blendingClampsAnAmountOutsideTheUnitRange(): void
    {
        $black = Color::hex('000000');
        $white = Color::hex('FFFFFF');

        self::assertSame($black->value, $black->blend($white, -1.0)->value);
        self::assertSame($white->value, $black->blend($white, 4.0)->value);
    }

    #[Test]
    public function blendingAnIndexedColourResolvesItToItsPaletteRgb(): void
    {
        self::assertSame(Color::rgb(205, 0, 0)->value, Color::ansi(AnsiColor::Red)->blend(Color::ansi(AnsiColor::Red), 1.0)->value);
    }

    #[Test]
    public function blendingABrightIndexReachesTheUpperHalfOfThePalette(): void
    {
        self::assertSame(Color::rgb(127, 127, 127)->value, Color::ansi(AnsiColor::BrightBlack)->blend(Color::ansi(AnsiColor::BrightBlack), 1.0)->value);
    }

    #[Test]
    public function blendingLandsOnTheNearestChannelRatherThanAlwaysTheSameWay(): void
    {
        $black = Color::rgb(0, 0, 0);
        $ten = Color::rgb(10, 10, 10);

        self::assertSame(Color::rgb(2, 2, 2)->value, $black->blend($ten, 0.24)->value, '2.4 goes down');
        self::assertSame(Color::rgb(3, 3, 3)->value, $black->blend($ten, 0.26)->value, 'and 2.6 goes up');
    }

    #[Test]
    public function theTerminalDefaultCannotBeBlended(): void
    {
        $red = Color::hex('FF0000');

        self::assertSame($red->value, $red->blend(Color::none(), 0.5)->value, 'nothing knowable to blend towards');
        self::assertSame(0, Color::none()->blend($red, 0.5)->value, 'and nothing knowable to blend from');
    }

    #[Test]
    public function blackAndWhiteAreTheWidestContrastThereIs(): void
    {
        self::assertEqualsWithDelta(21.0, Color::hex('000000')->contrastRatio(Color::hex('FFFFFF')), 1e-9);
    }

    #[Test]
    public function anInkTooCloseToItsPanelIsLiftedUntilItReads(): void
    {
        $panel = Color::hex('3E4451');
        $ink = Color::hex('5C6370');

        self::assertGreaterThanOrEqual(4.5, $ink->readableOn($panel)->contrastRatio($panel));
    }

    #[Test]
    public function theTerminalDefaultCannotBeLifted(): void
    {
        $panel = Color::hex('3E4451');

        self::assertSame(0, Color::none()->readableOn($panel)->value, 'no channels to lift');
        self::assertSame(
            Color::hex('5C6370')->value,
            Color::hex('5C6370')->readableOn(Color::none())->value,
            'and nothing knowable to lift away from',
        );
    }

    #[Test]
    public function anInkExactlyAtTheThresholdIsLeftAlone(): void
    {
        $panel = Color::hex('3E4451');
        $ink = Color::hex('ABB2BF');

        self::assertSame($ink->value, $ink->readableOn($panel, $ink->contrastRatio($panel))->value);
    }

    #[Test]
    public function anInkOneStepShortOfReadableMovesOnlyThatStep(): void
    {
        $panel = Color::hex('3E4451');
        $lifted = Color::hex('61AFEF')->readableOn($panel, 4.2);

        self::assertGreaterThanOrEqual(4.2, $lifted->contrastRatio($panel));
        self::assertLessThan(4.3, $lifted->contrastRatio($panel), 'a second step would already be too far');
    }

    #[Test]
    public function anInkFarFromReadableStopsAtTheFirstStepThatReads(): void
    {
        $black = Color::rgb(0, 0, 0);
        $white = Color::ansi(AnsiColor::BrightWhite);
        $lifted = Color::rgb(40, 40, 40)->readableOn($black);

        $steps = array_values(array_filter(
            range(1, 32),
            static fn (int $step): bool => Color::rgb(40, 40, 40)->blend($white, $step / 32)->value === $lifted->value,
        ));

        self::assertCount(1, $steps, 'the lift lands on one of the 32 steps towards white');
        self::assertGreaterThan(1, $steps[0]);
        self::assertGreaterThanOrEqual(4.5, $lifted->contrastRatio($black));
        self::assertLessThan(4.5, Color::rgb(40, 40, 40)->blend($white, ($steps[0] - 1) / 32)->contrastRatio($black));
    }

    #[Test]
    public function twoInksLiftedOffOnePanelStayDifferentColours(): void
    {
        $panel = Color::hex('3E4451');

        self::assertNotSame(
            Color::hex('E06C75')->readableOn($panel)->value,
            Color::hex('98C379')->readableOn($panel, 6.0)->value,
        );
    }

    #[Test]
    public function anInkThatAlreadyReadsIsLeftAlone(): void
    {
        $panel = Color::hex('3E4451');
        $ink = Color::hex('ABB2BF');

        self::assertSame($ink->value, $ink->readableOn($panel)->value);
    }

    #[Test]
    public function theTerminalDefaultHasNoContrastToClaim(): void
    {
        self::assertEqualsWithDelta(1.0, Color::none()->contrastRatio(Color::hex('FFFFFF')), 1e-9);
        self::assertEqualsWithDelta(1.0, Color::hex('FFFFFF')->contrastRatio(Color::none()), 1e-9);
    }
}
