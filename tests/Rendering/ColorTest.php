<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Rendering\Color;
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
        self::assertSame(Color::ANSI + 0, Color::ansi(0)->value);
        self::assertSame(Color::ANSI + 1, Color::ansi(1)->value);
        self::assertSame(Color::ANSI + 7, Color::ansi(7)->value);
    }

    /**
     * Kills L26 LessThanOrEqualTo: $index <= 15 mutated to $index < 15.
     */
    #[Test]
    public function ansi15IsTreatedAsBright(): void
    {
        $color = Color::ansi(15);
        self::assertSame(Color::BRIGHT + 7, $color->value);
        self::assertSame('97', $color->toSgr(false));
    }

    /**
     * Kills L45 UnwrapLtrim: ltrim($hex, '#') mutated to $hex.
     */
    #[Test]
    public function hexColorParsesLeadingHash(): void
    {
        $color = Color::hex('#FF0000');
        self::assertSame(Color::rgb(255, 0, 0)->value, $color->value);
        self::assertSame('38;2;255;0;0', $color->toSgr(false));
    }

    /**
     * Kills L46 CastInt: (int) hexdec() unwrapped.
     */
    #[Test]
    public function hexParsesExactRgbValues(): void
    {
        $color = Color::hex('0A141E');
        self::assertSame(Color::rgb(10, 20, 30)->value, $color->value);
    }

    /**
     * Kills L48 BitwiseAnd and DecrementInteger on the mask operations.
     */
    #[Test]
    public function hexExtractsRgbChannelsWithBitwiseMask(): void
    {
        $color = Color::hex('FF8040');
        self::assertSame('38;2;255;128;64', $color->toSgr(false));

        $colorOdd = Color::hex('010305');
        self::assertSame('38;2;1;3;5', $colorOdd->toSgr(false));
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
    public function toSgrForeground(): void
    {
        self::assertSame('31', Color::ansi(1)->toSgr(false));
    }

    #[Test]
    public function toSgrBackground(): void
    {
        self::assertSame('41', Color::ansi(1)->toSgr(true));
    }

    /**
     * Kills L79 BitwiseAnd, DecrementInteger in toSgr true color path.
     */
    #[Test]
    public function toSgrTrueColorExtractsChannels(): void
    {
        self::assertSame('38;2;255;6;183', Color::rgb(255, 6, 183)->toSgr(false));
        self::assertSame('48;2;255;6;183', Color::rgb(255, 6, 183)->toSgr(true));
        self::assertSame('38;2;1;1;1', Color::rgb(1, 1, 1)->toSgr(false));
        self::assertSame('38;2;255;255;255', Color::rgb(255, 255, 255)->toSgr(false));
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
    public function aThreeDigitHexReadsAsTheSixDigitColourItAbbreviates(): void
    {
        self::assertSame(Color::hex('FFAA00')->value, Color::hex('#FA0')->value);
    }

    /**
     * Kills L70 DecrementInteger/IncrementInteger: 48→47/49 or 38→37/39.
     */
    #[Test]
    public function toSgrExtendedBaseForegroundAndBackground(): void
    {
        self::assertSame('38;5;200', Color::color256(200)->toSgr(false));
        self::assertSame('48;5;200', Color::color256(200)->toSgr(true));
        self::assertSame('38;2;100;50;25', Color::rgb(100, 50, 25)->toSgr(false));
        self::assertSame('48;2;100;50;25', Color::rgb(100, 50, 25)->toSgr(true));
    }

    #[Test]
    public function noneToSgrReturnsDefault(): void
    {
        self::assertSame('39', Color::none()->toSgr(false));
        self::assertSame('49', Color::none()->toSgr(true));
    }

    /**
     * Kills L53 MatchArmRemoval for each named color and UnwrapStrToLower.
     */
    #[Test]
    public function namedBlackHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI, Color::named('black')->value);
    }

    #[Test]
    public function namedRedHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 1, Color::named('red')->value);
    }

    /**
     * Kills L56 DecrementInteger/IncrementInteger/Plus for green (ANSI + 2).
     */
    #[Test]
    public function namedGreenHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 2, Color::named('green')->value);
        self::assertSame('32', Color::named('green')->toSgr(false));
    }

    #[Test]
    public function namedYellowHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 3, Color::named('yellow')->value);
    }

    /**
     * Kills L58 DecrementInteger/IncrementInteger/Plus for blue (ANSI + 4).
     */
    #[Test]
    public function namedBlueHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 4, Color::named('blue')->value);
        self::assertSame('34', Color::named('blue')->toSgr(false));
    }

    #[Test]
    public function namedMagentaHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 5, Color::named('magenta')->value);
    }

    #[Test]
    public function namedCyanHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 6, Color::named('cyan')->value);
    }

    #[Test]
    public function namedWhiteHasCorrectValue(): void
    {
        self::assertSame(Color::ANSI + 7, Color::named('white')->value);
    }

    #[Test]
    public function namedGrayAndGreyAreBright(): void
    {
        self::assertSame(Color::BRIGHT, Color::named('gray')->value);
        self::assertSame(Color::BRIGHT, Color::named('grey')->value);
    }

    /**
     * Kills L53 UnwrapStrToLower.
     */
    #[Test]
    public function namedColorIsCaseInsensitive(): void
    {
        self::assertSame(Color::named('red')->value, Color::named('RED')->value);
        self::assertSame(Color::named('blue')->value, Color::named('Blue')->value);
        self::assertSame(Color::named('green')->value, Color::named('GREEN')->value);
    }

    #[Test]
    public function namedUnknownReturnsNone(): void
    {
        self::assertSame(0, Color::named('nonexistent')->value);
    }

    #[Test]
    public function brightAnsiSgrOutput(): void
    {
        self::assertSame('91', Color::ansi(9)->toSgr(false));
        self::assertSame('101', Color::ansi(9)->toSgr(true));
    }

    /**
     * Kills L86 GreaterThanOrEqualTo: >= self::BRIGHT mutated to >.
     */
    #[Test]
    public function ansi8IsBrightBoundary(): void
    {
        $color = Color::ansi(8);
        self::assertSame(Color::BRIGHT, $color->value);
        self::assertSame('90', $color->toSgr(false));
    }

    #[Test]
    public function brightAnsiRange(): void
    {
        for ($i = 8; $i <= 15; ++$i) {
            $color = Color::ansi($i);
            self::assertSame(Color::BRIGHT + ($i - 8), $color->value);
        }
    }

    /**
     * Kills L82 GreaterThanOrEqualTo: >= self::C256 mutated to >.
     */
    #[Test]
    public function color256ZeroBoundary(): void
    {
        $color = Color::color256(0);
        self::assertSame(Color::C256, $color->value);
        self::assertSame('38;5;0', $color->toSgr(false));
        self::assertSame('48;5;0', $color->toSgr(true));
    }

    #[Test]
    public function color256FullRange(): void
    {
        for ($i = 0; $i < 256; ++$i) {
            $color = Color::color256($i);
            self::assertSame(Color::C256 + $i, $color->value);
        }
    }

    /**
     * Kills L76 GreaterThanOrEqualTo: >= self::TRUE mutated to >.
     */
    #[Test]
    public function rgbBlackBoundaryToSgr(): void
    {
        $black = Color::rgb(0, 0, 0);
        self::assertSame(Color::TRUE, $black->value);
        self::assertSame('38;2;0;0;0', $black->toSgr(false));
        self::assertSame('48;2;0;0;0', $black->toSgr(true));
    }

    /**
     * Kills L77 Minus: $this->value - self::TRUE.
     */
    #[Test]
    public function rgbWhiteToSgr(): void
    {
        $white = Color::rgb(255, 255, 255);
        self::assertSame(Color::TRUE + 0xFFFFFF, $white->value);
        self::assertSame('38;2;255;255;255', $white->toSgr(false));
    }

    #[Test]
    public function ansiNegativeIndexThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Color::ansi(-1);
    }

    #[Test]
    public function ansiIndex16Throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Color::ansi(16);
    }

    #[Test]
    public function ansiIndex0IsValid(): void
    {
        $color = Color::ansi(0);
        self::assertSame(Color::ANSI, $color->value);
    }

    #[Test]
    public function ansiIndex15IsValid(): void
    {
        $color = Color::ansi(15);
        self::assertSame(Color::BRIGHT + 7, $color->value);
    }

    #[Test]
    public function fromValueRoundTrip(): void
    {
        $ansi = Color::ansi(3);
        self::assertSame($ansi->value, Color::fromValue($ansi->value)->value);
        self::assertSame($ansi->toSgr(false), Color::fromValue($ansi->value)->toSgr(false));

        $bright = Color::ansi(10);
        self::assertSame($bright->toSgr(true), Color::fromValue($bright->value)->toSgr(true));

        $c256 = Color::color256(42);
        self::assertSame($c256->toSgr(false), Color::fromValue($c256->value)->toSgr(false));

        $trueColor = Color::rgb(255, 128, 0);
        self::assertSame($trueColor->toSgr(false), Color::fromValue($trueColor->value)->toSgr(false));

        $none = Color::none();
        self::assertSame($none->toSgr(false), Color::fromValue($none->value)->toSgr(false));
        self::assertSame($none->toSgr(true), Color::fromValue($none->value)->toSgr(true));
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
    }

    #[Test]
    #[DataProvider('toAnsi16Cases')]
    public function downsampleToAnsiReducesTo16Colors(Color $color, int $expectedAnsiIndex): void
    {
        self::assertSame(Color::ansi($expectedAnsiIndex)->value, $color->downsampleTo(ColorProfile::Ansi)->value);
    }

    #[Test]
    public function downsampleToAsciiStripsAllColor(): void
    {
        self::assertSame(0, Color::rgb(255, 0, 0)->downsampleTo(ColorProfile::Ascii)->value);
        self::assertSame(0, Color::color256(196)->downsampleTo(ColorProfile::Ascii)->value);
        self::assertSame(0, Color::ansi(9)->downsampleTo(ColorProfile::Ascii)->value);
        self::assertSame(0, Color::none()->downsampleTo(ColorProfile::Ascii)->value);
    }

    #[Test]
    public function downsampleIdentitiesArePreserved(): void
    {
        $trueColor = Color::rgb(1, 2, 3);
        self::assertSame($trueColor->value, $trueColor->downsampleTo(ColorProfile::TrueColor)->value, 'TrueColor profile keeps everything');

        $indexed = Color::color256(123);
        self::assertSame($indexed->value, $indexed->downsampleTo(ColorProfile::Ansi256)->value, '256-color values survive an Ansi256 profile');

        $basic = Color::ansi(5);
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
        yield 'basic blue' => [Color::ansi(4), 15];
        yield 'the first bright ansi index' => [Color::ansi(8), 0];
        yield 'a grey dark enough for the linear part of the curve' => [Color::rgb(10, 10, 10), 15];
        yield 'a mid tone carried by its blue' => [Color::rgb(120, 120, 220), 0];
    }

    #[Test]
    #[DataProvider('contrastingTextCases')]
    public function contrastingTextCrossesOverWithBackgroundLuminance(Color $background, int $expectedAnsiIndex): void
    {
        self::assertSame(Color::ansi($expectedAnsiIndex)->value, $background->contrastingText()->value);
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
        self::assertSame(Color::rgb(205, 0, 0)->value, Color::ansi(1)->blend(Color::ansi(1), 1.0)->value);
    }

    #[Test]
    public function blendingABrightIndexReachesTheUpperHalfOfThePalette(): void
    {
        self::assertSame(Color::rgb(127, 127, 127)->value, Color::ansi(8)->blend(Color::ansi(8), 1.0)->value);
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
}
