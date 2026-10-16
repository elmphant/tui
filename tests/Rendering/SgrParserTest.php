<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\SgrParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SgrParserTest extends TestCase
{
    // --- Reset behavior (L13-18, L22-25) ---

    #[Test]
    public function aColourWithAnEmptyIndexReadsAsTheFirstPaletteEntry(): void
    {
        $parser = new SgrParser();

        $parser->parse('38;5;');

        self::assertSame(Color::C256, $parser->foreground);
    }

    #[Test]
    public function aTrueColourWithEmptyChannelsReadsAsBlack(): void
    {
        $parser = new SgrParser();

        $parser->parse('48;2;;;');

        self::assertSame(Color::TRUE, $parser->background);
    }

    #[Test]
    public function resetClearsForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('31');
        $parser->reset();
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function resetClearsBackground(): void
    {
        $parser = new SgrParser();
        $parser->parse('41');
        $parser->reset();
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function resetClearsAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('1');
        $parser->reset();
        self::assertSame(0, $parser->attributes);
    }

    #[Test]
    public function parseEmptyStringResetsState(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;31;41');
        $parser->parse('');
        self::assertSame(0, $parser->foreground);
        self::assertSame(0, $parser->background);
        self::assertSame(0, $parser->attributes);
    }

    #[Test]
    public function parseSgrCodeZeroResets(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;31;41');
        $parser->parse('0');
        self::assertSame(0, $parser->foreground);
        self::assertSame(0, $parser->background);
        self::assertSame(0, $parser->attributes);
    }

    #[Test]
    public function parseEarlyReturnAfterResetDoesNotProcessFurtherCodes(): void
    {
        $parser = new SgrParser();
        // "0" alone triggers early return — if return is removed, the while loop runs
        // but since "0" is only one param, the loop would still reset. We need a different
        // approach: verify that parse('0') with no semicolon returns early and doesn't crash.
        // The ReturnRemoval mutant on L24 would cause the code to fall through to the
        // explode/while logic, which would process '0' again via match arm — still a reset.
        // So we need to test that '' (empty) triggers reset and returns.
        $parser->parse('1;31');
        $parser->parse('');
        self::assertSame(0, $parser->attributes);
        self::assertSame(0, $parser->foreground);
    }

    // --- Match arm for SGR 0 inside a compound sequence (L35) ---

    #[Test]
    public function sgrResetMidSequenceResetsAllState(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;31;0;32');
        // 0 resets everything, then 32 sets foreground to green
        self::assertSame(0, $parser->attributes);
        self::assertSame(Color::ANSI + 2, $parser->foreground);
    }

    // --- Individual attribute match arms (L36-42) ---

    #[Test]
    public function sgrParsesBoldAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('1');
        self::assertSame(Attr::BOLD, $parser->attributes);
    }

    #[Test]
    public function sgrParsesFaintAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('2');
        self::assertSame(Attr::FAINT, $parser->attributes);
    }

    #[Test]
    public function sgrParsesItalicAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('3');
        self::assertSame(Attr::ITALIC, $parser->attributes);
    }

    #[Test]
    public function sgrParsesUnderlineAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('4');
        self::assertSame(Attr::UNDERLINE, $parser->attributes);
    }

    #[Test]
    public function sgrParsesBlinkAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('5');
        self::assertSame(Attr::BLINK, $parser->attributes);
    }

    #[Test]
    public function sgrParsesReverseAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('7');
        self::assertSame(Attr::REVERSE, $parser->attributes);
    }

    #[Test]
    public function sgrParsesStrikethroughAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('9');
        self::assertSame(Attr::STRIKETHROUGH, $parser->attributes);
    }

    // --- Attribute removal match arms (L43-48) ---

    #[Test]
    public function sgrRemovesBoldAndFaint(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;2');
        self::assertSame(Attr::BOLD | Attr::FAINT, $parser->attributes);
        $parser->parse('22');
        self::assertSame(0, $parser->attributes);
    }

    #[Test]
    public function sgrRemovesBoldButKeepsOtherAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;3');
        $parser->parse('22');
        self::assertSame(Attr::ITALIC, $parser->attributes);
    }

    #[Test]
    public function sgrRemovesItalic(): void
    {
        $parser = new SgrParser();
        $parser->parse('3;1');
        $parser->parse('23');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::ITALIC);
    }

    #[Test]
    public function sgrRemovesUnderline(): void
    {
        $parser = new SgrParser();
        $parser->parse('4;1');
        $parser->parse('24');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::UNDERLINE);
    }

    #[Test]
    public function sgrRemovesBlink(): void
    {
        $parser = new SgrParser();
        $parser->parse('5;1');
        $parser->parse('25');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::BLINK);
    }

    #[Test]
    public function sgrRemovesReverse(): void
    {
        $parser = new SgrParser();
        $parser->parse('7;1');
        $parser->parse('27');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::REVERSE);
    }

    #[Test]
    public function sgrRemovesStrikethrough(): void
    {
        $parser = new SgrParser();
        $parser->parse('9;1');
        $parser->parse('29');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::STRIKETHROUGH);
    }

    // --- Foreground ANSI colors 30-37 boundary tests (L49) ---

    #[Test]
    public function sgrParsesAnsiForegroundBlack(): void
    {
        $parser = new SgrParser();
        $parser->parse('30');
        self::assertSame(Color::ANSI + 0, $parser->foreground);
    }

    #[Test]
    public function sgrParsesAnsiForegroundRed(): void
    {
        $parser = new SgrParser();
        $parser->parse('31');
        self::assertSame(Color::ANSI + 1, $parser->foreground);
    }

    #[Test]
    public function sgrParsesAnsiForegroundWhite(): void
    {
        $parser = new SgrParser();
        $parser->parse('37');
        self::assertSame(Color::ANSI + 7, $parser->foreground);
    }

    // --- Default foreground reset (L50) ---

    #[Test]
    public function sgrResetsDefaultForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('31');
        $parser->parse('39');
        self::assertSame(0, $parser->foreground);
    }

    // --- Background ANSI colors 40-47 boundary tests (L51) ---

    #[Test]
    public function sgrParsesAnsiBackgroundBlack(): void
    {
        $parser = new SgrParser();
        $parser->parse('40');
        self::assertSame(Color::ANSI + 0, $parser->background);
    }

    #[Test]
    public function sgrParsesAnsiBackgroundRed(): void
    {
        $parser = new SgrParser();
        $parser->parse('41');
        self::assertSame(Color::ANSI + 1, $parser->background);
    }

    #[Test]
    public function sgrParsesAnsiBackgroundWhite(): void
    {
        $parser = new SgrParser();
        $parser->parse('47');
        self::assertSame(Color::ANSI + 7, $parser->background);
    }

    // --- Default background reset (L52) ---

    #[Test]
    public function sgrResetsDefaultBackground(): void
    {
        $parser = new SgrParser();
        $parser->parse('41');
        $parser->parse('49');
        self::assertSame(0, $parser->background);
    }

    // --- Bright foreground colors 90-97 boundary tests (L53) ---

    #[Test]
    public function sgrParsesBrightForegroundBlack(): void
    {
        $parser = new SgrParser();
        $parser->parse('90');
        self::assertSame(Color::BRIGHT + 0, $parser->foreground);
    }

    #[Test]
    public function sgrParsesBrightForegroundRed(): void
    {
        $parser = new SgrParser();
        $parser->parse('91');
        self::assertSame(Color::BRIGHT + 1, $parser->foreground);
    }

    #[Test]
    public function sgrParsesBrightForegroundWhite(): void
    {
        $parser = new SgrParser();
        $parser->parse('97');
        self::assertSame(Color::BRIGHT + 7, $parser->foreground);
    }

    // --- Bright background colors 100-107 boundary tests (L54) ---

    #[Test]
    public function sgrParsesBrightBackgroundBlack(): void
    {
        $parser = new SgrParser();
        $parser->parse('100');
        self::assertSame(Color::BRIGHT + 0, $parser->background);
    }

    #[Test]
    public function sgrParsesBrightBackgroundRed(): void
    {
        $parser = new SgrParser();
        $parser->parse('101');
        self::assertSame(Color::BRIGHT + 1, $parser->background);
    }

    #[Test]
    public function sgrParsesBrightBackgroundWhite(): void
    {
        $parser = new SgrParser();
        $parser->parse('107');
        self::assertSame(Color::BRIGHT + 7, $parser->background);
    }

    // --- 256-color foreground (L55, L71-78) ---

    #[Test]
    public function sgrParsesAnsi256ForegroundColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5;200');
        self::assertSame(Color::C256 + 200, $parser->foreground);
    }

    #[Test]
    public function sgrParsesAnsi256ForegroundColorZero(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5;0');
        self::assertSame(Color::C256 + 0, $parser->foreground);
    }

    #[Test]
    public function sgrParsesAnsi256ForegroundColor255(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5;255');
        self::assertSame(Color::C256 + 255, $parser->foreground);
    }

    // --- 256-color background (L55, L71-78) ---

    #[Test]
    public function sgrParsesAnsi256BackgroundColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;5;100');
        self::assertSame(Color::C256 + 100, $parser->background);
    }

    #[Test]
    public function sgrParsesAnsi256BackgroundDoesNotAffectForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;5;100');
        self::assertSame(0, $parser->foreground);
        self::assertSame(Color::C256 + 100, $parser->background);
    }

    // --- RGB foreground (L55, L81-91) ---

    #[Test]
    public function sgrParsesRgbForegroundColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;255;6;183');
        self::assertSame(Color::rgb(255, 6, 183)->value, $parser->foreground);
    }

    #[Test]
    public function sgrParsesRgbForegroundBlack(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;0;0;0');
        self::assertSame(Color::TRUE, $parser->foreground);
    }

    #[Test]
    public function sgrParsesRgbForegroundWhite(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;255;255;255');
        self::assertSame(Color::rgb(255, 255, 255)->value, $parser->foreground);
    }

    #[Test]
    public function sgrParsesRgbForegroundRedChannel(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;128;0;0');
        self::assertSame(Color::TRUE | (128 << 16), $parser->foreground);
    }

    #[Test]
    public function sgrParsesRgbForegroundGreenChannel(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;0;128;0');
        self::assertSame(Color::TRUE | (128 << 8), $parser->foreground);
    }

    #[Test]
    public function sgrParsesRgbForegroundBlueChannel(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;0;0;128');
        self::assertSame(Color::TRUE | 128, $parser->foreground);
    }

    // --- RGB background (L55, L81-91) ---

    #[Test]
    public function sgrParsesRgbBackgroundColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;100;150;200');
        self::assertSame(Color::rgb(100, 150, 200)->value, $parser->background);
    }

    #[Test]
    public function sgrParsesRgbBackgroundDoesNotAffectForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;100;150;200');
        self::assertSame(0, $parser->foreground);
        self::assertSame(Color::rgb(100, 150, 200)->value, $parser->background);
    }

    // --- Incomplete extended color sequences (L65-67, L94) ---

    #[Test]
    public function sgrParsesIncompleteExtendedColorSgr38Alone(): void
    {
        $parser = new SgrParser();
        $parser->parse('38');
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrParsesIncomplete256ColorMissingValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5');
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrParsesIncompleteRgbColorMissingComponents(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;255;128');
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrParsesUnknownExtendedColorModeSkipsOne(): void
    {
        // Mode 3 is unknown, should skip mode byte and continue
        $parser = new SgrParser();
        $parser->parse('38;3;31');
        // After 38;3, parseExtendedColor returns index+1=1, then index++ makes it 2
        // which should be parsed as foreground red (SGR 31)
        self::assertSame(Color::ANSI + 1, $parser->foreground);
        // Ensure mode byte '3' was NOT interpreted as italic
        self::assertSame(0, $parser->attributes);
    }

    // --- Bitwise operations precision (L36-42, L43-48) ---

    #[Test]
    public function sgrBoldDoesNotSetOtherAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('1');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::FAINT);
        self::assertSame(0, $parser->attributes & Attr::ITALIC);
    }

    #[Test]
    public function sgrFaintDoesNotSetBold(): void
    {
        $parser = new SgrParser();
        $parser->parse('2');
        self::assertSame(Attr::FAINT, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::BOLD);
    }

    #[Test]
    public function sgrBoldOrPreservesExistingAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('3');
        $parser->parse('1');
        self::assertSame(Attr::BOLD | Attr::ITALIC, $parser->attributes);
    }

    #[Test]
    public function sgrRemoveBoldFaintPreservesItalic(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;2;3');
        $parser->parse('22');
        self::assertSame(Attr::ITALIC, $parser->attributes);
    }

    // --- Combined sequences ---

    #[Test]
    public function sgrParsesMultipleAttributesInOneSequence(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;3;4;7;9');
        self::assertSame(
            Attr::BOLD | Attr::ITALIC | Attr::UNDERLINE | Attr::REVERSE | Attr::STRIKETHROUGH,
            $parser->attributes,
        );
    }

    #[Test]
    public function sgrParsesAttributeAndColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;31;42');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(Color::ANSI + 1, $parser->foreground);
        self::assertSame(Color::ANSI + 2, $parser->background);
    }

    #[Test]
    public function sgrParsesTrueColorFollowedByAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;10;20;30;1');
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->foreground);
        self::assertSame(Attr::BOLD, $parser->attributes);
    }

    #[Test]
    public function sgrParses256ColorFollowedByAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5;42;3');
        self::assertSame(Color::C256 + 42, $parser->foreground);
        self::assertSame(Attr::ITALIC, $parser->attributes);
    }

    // --- Boundary values that should NOT match (off-by-one mutants) ---

    #[Test]
    public function sgrCode29DoesNotSetForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('9');
        $parser->parse('29');
        // SGR 29 removes strikethrough, does NOT set foreground
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrCode38DoesNotDirectlySetForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('38');
        // 38 alone (incomplete) should not set foreground to a color
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrCode48DoesNotDirectlySetBackground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48');
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrCode89DoesNotSetForeground(): void
    {
        // 89 is between normal fg (30-37) and bright fg (90-97) ranges — should be ignored
        $parser = new SgrParser();
        $parser->parse('89');
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrCode99DoesNotSetForeground(): void
    {
        // 99 is outside bright fg range (90-97) — should be ignored
        $parser = new SgrParser();
        $parser->parse('99');
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrCode98DoesNotSetAnything(): void
    {
        // 98 is between bright fg (90-97) and bright bg (100-107) — should be ignored
        $parser = new SgrParser();
        $parser->parse('98');
        self::assertSame(0, $parser->foreground);
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrCode108DoesNotSetBackground(): void
    {
        // 108 is outside bright bg range (100-107) — should be ignored
        $parser = new SgrParser();
        $parser->parse('108');
        self::assertSame(0, $parser->background);
    }

    // --- RGB component masking with 0xFF (L83-85) ---

    #[Test]
    public function sgrRgbComponentsAreMaskedTo8Bits(): void
    {
        $parser = new SgrParser();
        // 256 should be masked to 0 by & 0xFF
        $parser->parse('38;2;256;257;258');
        self::assertSame(Color::TRUE | (0 << 16) | (1 << 8) | 2, $parser->foreground);
    }

    #[Test]
    public function sgrAnsi256ColorIsMaskedTo8Bits(): void
    {
        $parser = new SgrParser();
        // 256 should be masked to 0 by & 0xFF
        $parser->parse('38;5;256');
        self::assertSame(Color::C256 + 0, $parser->foreground);
    }

    // --- Unknown/unhandled SGR codes (default arm) ---

    #[Test]
    public function sgrUnknownCodeIsIgnored(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;999;31');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(Color::ANSI + 1, $parser->foreground);
    }

    // --- Statefulness across calls ---

    #[Test]
    public function sgrParserPreservesStateBetweenCalls(): void
    {
        $parser = new SgrParser();
        $parser->parse('1');
        $parser->parse('31');
        self::assertSame(Attr::BOLD, $parser->attributes);
        self::assertSame(Color::ANSI + 1, $parser->foreground);
    }

    // --- Each foreground color individually ---

    #[Test]
    public function sgrParsesAllAnsiForegroundColors(): void
    {
        for ($color = 0; $color <= 7; ++$color) {
            $parser = new SgrParser();
            $parser->parse((string)(30 + $color));
            self::assertSame(
                Color::ANSI + $color,
                $parser->foreground,
                'SGR code ' . (30 + $color) . " should set foreground to ANSI color $color",
            );
        }
    }

    #[Test]
    public function sgrParsesAllAnsiBackgroundColors(): void
    {
        for ($color = 0; $color <= 7; ++$color) {
            $parser = new SgrParser();
            $parser->parse((string)(40 + $color));
            self::assertSame(
                Color::ANSI + $color,
                $parser->background,
                'SGR code ' . (40 + $color) . " should set background to ANSI color $color",
            );
        }
    }

    #[Test]
    public function sgrParsesAllBrightForegroundColors(): void
    {
        for ($color = 0; $color <= 7; ++$color) {
            $parser = new SgrParser();
            $parser->parse((string)(90 + $color));
            self::assertSame(
                Color::BRIGHT + $color,
                $parser->foreground,
                'SGR code ' . (90 + $color) . " should set foreground to bright color $color",
            );
        }
    }

    #[Test]
    public function sgrParsesAllBrightBackgroundColors(): void
    {
        for ($color = 0; $color <= 7; ++$color) {
            $parser = new SgrParser();
            $parser->parse((string)(100 + $color));
            self::assertSame(
                Color::BRIGHT + $color,
                $parser->background,
                'SGR code ' . (100 + $color) . " should set background to bright color $color",
            );
        }
    }

    // --- Attribute accumulation via bitwise OR ---

    #[Test]
    public function sgrAttributesAccumulateWithBitwiseOr(): void
    {
        $parser = new SgrParser();
        $parser->parse('1');
        $parser->parse('2');
        self::assertSame(Attr::BOLD | Attr::FAINT, $parser->attributes);
    }

    #[Test]
    public function sgrDuplicateAttributeDoesNotChange(): void
    {
        $parser = new SgrParser();
        $parser->parse('1');
        $parser->parse('1');
        self::assertSame(Attr::BOLD, $parser->attributes);
    }

    // --- Foreground/background color overwrite ---

    #[Test]
    public function sgrForegroundColorOverwritesPrevious(): void
    {
        $parser = new SgrParser();
        $parser->parse('31');
        $parser->parse('32');
        self::assertSame(Color::ANSI + 2, $parser->foreground);
    }

    #[Test]
    public function sgrBackgroundColorOverwritesPrevious(): void
    {
        $parser = new SgrParser();
        $parser->parse('41');
        $parser->parse('42');
        self::assertSame(Color::ANSI + 2, $parser->background);
    }

    // --- Extended color with 48 (background) followed by more params ---

    #[Test]
    public function sgrParses256BackgroundFollowedByAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;5;42;1');
        self::assertSame(Color::C256 + 42, $parser->background);
        self::assertSame(Attr::BOLD, $parser->attributes);
    }

    #[Test]
    public function sgrParsesRgbBackgroundFollowedByAttribute(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;10;20;30;1');
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->background);
        self::assertSame(Attr::BOLD, $parser->attributes);
    }

    // --- Incomplete extended color for background ---

    #[Test]
    public function sgrParsesIncomplete256BackgroundColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;5');
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrParsesIncompleteRgbBackgroundColor(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;255;128');
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrParsesIncompleteExtendedBackgroundAlone(): void
    {
        $parser = new SgrParser();
        $parser->parse('48');
        self::assertSame(0, $parser->background);
    }

    // --- Return index correctness in parseExtendedColor (L66, L78, L91, L94) ---

    #[Test]
    public function sgr256ColorReturnIndexSkipsConsumedParams(): void
    {
        // '38;5;42;3' — parseExtendedColor should return index+2,
        // so after index++ the next param is '3' (italic)
        $parser = new SgrParser();
        $parser->parse('38;5;42;3');
        self::assertSame(Color::C256 + 42, $parser->foreground);
        self::assertSame(Attr::ITALIC, $parser->attributes);
        // If return index+2 is mutated to index+1, '42' would be parsed as
        // SGR 42 (background green), and '3' as italic
    }

    #[Test]
    public function sgrRgbColorReturnIndexSkipsConsumedParams(): void
    {
        // '38;2;10;20;30;4' — parseExtendedColor should return index+4,
        // so after index++ the next param is '4' (underline)
        $parser = new SgrParser();
        $parser->parse('38;2;10;20;30;4');
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->foreground);
        self::assertSame(Attr::UNDERLINE, $parser->attributes);
    }

    #[Test]
    public function sgrIncompleteExtendedColorReturnIndexAdvances(): void
    {
        // '38;1' — mode=1 is unknown, parseExtendedColor returns index+1
        // so after index++, loop ends (only 2 params)
        // Importantly, '1' should NOT be treated as bold
        $parser = new SgrParser();
        $parser->parse('38;1');
        self::assertSame(0, $parser->attributes);
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function sgrCompletelyAloneExtendedColorReturnsIndex(): void
    {
        // '38' alone — parseExtendedColor returns index (0) because
        // index+1 >= partCount. Then index++ makes it 1, loop ends.
        $parser = new SgrParser();
        $parser->parse('38');
        self::assertSame(0, $parser->foreground);
    }

    // --- Verify that foreground does not bleed into background and vice versa ---

    #[Test]
    public function sgrAnsi256ForegroundDoesNotAffectBackground(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5;42');
        self::assertSame(Color::C256 + 42, $parser->foreground);
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrRgbForegroundDoesNotAffectBackground(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;10;20;30');
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->foreground);
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrRgbBackgroundDoesNotAffectForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;10;20;30');
        self::assertSame(0, $parser->foreground);
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->background);
    }

    // --- Verify exact color values to catch arithmetic mutants ---

    #[Test]
    public function sgrForeground30ProducesExactAnsiValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('30');
        // Color::ANSI + 30 - 30 = Color::ANSI + 0 = 0x01
        self::assertSame(0x01, $parser->foreground);
    }

    #[Test]
    public function sgrForeground37ProducesExactAnsiValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('37');
        // Color::ANSI + 37 - 30 = 0x01 + 7 = 0x08
        self::assertSame(0x08, $parser->foreground);
    }

    #[Test]
    public function sgrBackground40ProducesExactAnsiValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('40');
        // Color::ANSI + 40 - 40 = 0x01
        self::assertSame(0x01, $parser->background);
    }

    #[Test]
    public function sgrBackground47ProducesExactAnsiValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('47');
        // Color::ANSI + 47 - 40 = 0x01 + 7 = 0x08
        self::assertSame(0x08, $parser->background);
    }

    #[Test]
    public function sgrBrightForeground90ProducesExactValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('90');
        // Color::BRIGHT + 90 - 90 = 0x10
        self::assertSame(0x10, $parser->foreground);
    }

    #[Test]
    public function sgrBrightForeground97ProducesExactValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('97');
        // Color::BRIGHT + 97 - 90 = 0x10 + 7 = 0x17
        self::assertSame(0x17, $parser->foreground);
    }

    #[Test]
    public function sgrBrightBackground100ProducesExactValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('100');
        // Color::BRIGHT + 100 - 100 = 0x10
        self::assertSame(0x10, $parser->background);
    }

    #[Test]
    public function sgrBrightBackground107ProducesExactValue(): void
    {
        $parser = new SgrParser();
        $parser->parse('107');
        // Color::BRIGHT + 107 - 100 = 0x10 + 7 = 0x17
        self::assertSame(0x17, $parser->background);
    }

    // --- Verify RGB bitwise construction (L82-85) ---

    #[Test]
    public function sgrRgbColorBitwiseOrConstruction(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;1;2;3');
        // Color::TRUE | (1 << 16) | (2 << 8) | 3 = 0x1000000 | 0x10000 | 0x200 | 0x3
        $expected = Color::TRUE | (1 << 16) | (2 << 8) | 3;
        self::assertSame($expected, $parser->foreground);
    }

    #[Test]
    public function sgrRgbColorChannelsIndependent(): void
    {
        // Red-only: verify green and blue are zero
        $parser = new SgrParser();
        $parser->parse('38;2;255;0;0');
        $value = $parser->foreground - Color::TRUE;
        self::assertSame(255, ($value >> 16) & 0xFF);
        self::assertSame(0, ($value >> 8) & 0xFF);
        self::assertSame(0, $value & 0xFF);
    }

    // --- Verify 256-color uses & 0xFF masking (L72) ---

    #[Test]
    public function sgrAnsi256ColorClampsNegativeToMask(): void
    {
        $parser = new SgrParser();
        // -1 cast to int then & 0xFF = 255
        $parser->parse('38;5;-1');
        // (int)'-1' = -1, -1 & 0xFF = 255
        self::assertSame(Color::C256 + 255, $parser->foreground);
    }

    // --- Attribute removal preserves unrelated bits precisely ---

    #[Test]
    public function sgrRemoveUnderlinePreservesAllOtherAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;2;3;4;5;7;9');
        $allAttributes = Attr::BOLD | Attr::FAINT | Attr::ITALIC | Attr::UNDERLINE
            | Attr::BLINK | Attr::REVERSE | Attr::STRIKETHROUGH;
        self::assertSame($allAttributes, $parser->attributes);
        $parser->parse('24');
        self::assertSame($allAttributes & ~Attr::UNDERLINE, $parser->attributes);
    }

    #[Test]
    public function sgrRemoveBlinkPreservesAllOtherAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;2;3;4;5;7;9');
        $parser->parse('25');
        $expected = Attr::BOLD | Attr::FAINT | Attr::ITALIC | Attr::UNDERLINE
            | Attr::REVERSE | Attr::STRIKETHROUGH;
        self::assertSame($expected, $parser->attributes);
    }

    #[Test]
    public function sgrRemoveReversePreservesAllOtherAttributes(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;2;3;4;5;7;9');
        $parser->parse('27');
        $expected = Attr::BOLD | Attr::FAINT | Attr::ITALIC | Attr::UNDERLINE
            | Attr::BLINK | Attr::STRIKETHROUGH;
        self::assertSame($expected, $parser->attributes);
    }

    // --- Verify isBackground flag in parseExtendedColor (L55, L73, L86) ---

    #[Test]
    public function sgrExtendedColor38SetsForegroundNot48Background(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;5;100');
        self::assertSame(Color::C256 + 100, $parser->foreground);
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrExtendedColor48SetsBackgroundNot38Foreground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;5;100');
        self::assertSame(0, $parser->foreground);
        self::assertSame(Color::C256 + 100, $parser->background);
    }

    #[Test]
    public function sgrExtendedRgb38SetsForegroundOnly(): void
    {
        $parser = new SgrParser();
        $parser->parse('38;2;50;60;70');
        self::assertSame(Color::rgb(50, 60, 70)->value, $parser->foreground);
        self::assertSame(0, $parser->background);
    }

    #[Test]
    public function sgrExtendedRgb48SetsBackgroundOnly(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;50;60;70');
        self::assertSame(0, $parser->foreground);
        self::assertSame(Color::rgb(50, 60, 70)->value, $parser->background);
    }

    // --- Boundary condition: index + 2 < partCount for 256-color (L71) ---

    #[Test]
    public function sgrAnsi256ExactlyEnoughParams(): void
    {
        // '38;5;42' has 3 parts, index=0, need index+2 < 3 → 2 < 3 ✓
        $parser = new SgrParser();
        $parser->parse('38;5;42');
        self::assertSame(Color::C256 + 42, $parser->foreground);
    }

    // --- Boundary condition: index + 4 < partCount for RGB (L81) ---

    #[Test]
    public function sgrRgbExactlyEnoughParams(): void
    {
        // '38;2;10;20;30' has 5 parts, index=0, need index+4 < 5 → 4 < 5 ✓
        $parser = new SgrParser();
        $parser->parse('38;2;10;20;30');
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->foreground);
    }

    #[Test]
    public function sgrRgbOneShortOfEnoughParams(): void
    {
        // '38;2;10;20' has 4 parts, index=0, need index+4 < 4 → 4 < 4 ✗
        $parser = new SgrParser();
        $parser->parse('38;2;10;20');
        self::assertSame(0, $parser->foreground);
    }

    // --- CastInt mutations (L32, L69, L72, L83-85) ---

    #[Test]
    public function sgrNonNumericParamIsTreatedAsZero(): void
    {
        $parser = new SgrParser();
        $parser->parse('abc');
        // (int)'abc' = 0, triggers reset
        self::assertSame(0, $parser->foreground);
        self::assertSame(0, $parser->attributes);
    }

    // --- Verify the `$parameter === 48` branch in the match (L55) ---

    #[Test]
    public function sgr48Is256BackgroundNotForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;5;200');
        self::assertSame(Color::C256 + 200, $parser->background);
        self::assertNotSame(Color::C256 + 200, $parser->foreground);
    }

    #[Test]
    public function sgr48IsRgbBackgroundNotForeground(): void
    {
        $parser = new SgrParser();
        $parser->parse('48;2;100;200;50');
        self::assertSame(Color::rgb(100, 200, 50)->value, $parser->background);
        self::assertNotSame(Color::rgb(100, 200, 50)->value, $parser->foreground);
    }

    #[Test]
    public function emptyOrZeroResetBothBranches(): void
    {
        $parser = new SgrParser();
        $parser->parse('1;31');
        $parser->parse('');
        self::assertSame(0, $parser->foreground);
        self::assertSame(0, $parser->attributes);

        $parser->parse('1;31');
        $parser->parse('0');
        self::assertSame(0, $parser->foreground);
        self::assertSame(0, $parser->attributes);
    }

    #[Test]
    public function parseExtendedColorBoundaryIndexPlusOneEqualsPartCount(): void
    {
        // '38' has 1 part. index=0, index+1=1 >= partCount=1. Returns index=0.
        $parser = new SgrParser();
        $parser->parse('38');
        self::assertSame(0, $parser->foreground);
    }

    #[Test]
    public function parseExtendedColor256BoundaryExactlyThreeParams(): void
    {
        // '38;5;42' has 3 parts. index=0, mode=5, index+2=2 < partCount=3 => processes.
        $parser = new SgrParser();
        $parser->parse('38;5;42');
        self::assertSame(Color::C256 + 42, $parser->foreground);
    }

    #[Test]
    public function parseExtendedColorRgbReturnIndexIsExactlyPlus4(): void
    {
        // '38;2;10;20;30;1' — if return were index+3 instead of index+4,
        // '30' would be parsed as SGR 30 (black foreground), overwriting the RGB color.
        // If return were index+5, '1' would be skipped entirely.
        $parser = new SgrParser();
        $parser->parse('38;2;10;20;30;1');
        self::assertSame(Color::rgb(10, 20, 30)->value, $parser->foreground);
        self::assertSame(Attr::BOLD, $parser->attributes);
    }

    #[Test]
    public function parseExtendedColorUnknownModeReturnIndexIsExactlyPlus1(): void
    {
        // '38;9;1;3' — mode=9 is unknown, returns index+1=1.
        // After index++, index=2 which is '1' (bold). Then index=3 is '3' (italic).
        // If return were index+0, '9' would be reprocessed as SGR 9 (strikethrough).
        // If return were index+2, '3' would be skipped.
        $parser = new SgrParser();
        $parser->parse('38;9;1;3');
        self::assertSame(Attr::BOLD | Attr::ITALIC, $parser->attributes);
        self::assertSame(0, $parser->attributes & Attr::STRIKETHROUGH);
    }

    #[Test]
    public function parseExtendedColor256ReturnIndexIsExactlyPlus2(): void
    {
        // '38;5;42;1;3' — returns index+2=2. After index++, index=3 is '1' (bold).
        // Then index=4 is '3' (italic).
        // If return were index+1, '42' would be reparsed as SGR 42 (green bg).
        // If return were index+3, '1' would be skipped.
        $parser = new SgrParser();
        $parser->parse('38;5;42;1;3');
        self::assertSame(Color::C256 + 42, $parser->foreground);
        self::assertSame(Attr::BOLD | Attr::ITALIC, $parser->attributes);
        self::assertSame(0, $parser->background);
    }
}
