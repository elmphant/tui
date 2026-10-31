<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\CharWidth;
use IntlChar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CharWidthTest extends TestCase
{
    #[Test]
    public function asciiWidthIsOne(): void
    {
        self::assertSame(1, CharWidth::codepointWidth('A'));
        self::assertSame(1, CharWidth::codepointWidth('z'));
    }

    #[Test]
    public function cjkWidthIsTwo(): void
    {
        self::assertSame(2, CharWidth::codepointWidth("\u{4E16}")); // 世
    }

    #[Test]
    public function stringWidth(): void
    {
        self::assertSame(5, CharWidth::stringWidth('Hello'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textPresentationEmoji(): iterable
    {
        yield 'heavy check mark' => ["\u{2714}"];
        yield 'heavy multiplication x' => ["\u{2716}"];
        yield 'heavy black heart' => ["\u{2764}"];
        yield 'black rightwards arrow' => ["\u{27A1}"];
        yield 'upwards black arrow' => ["\u{2B06}"];
        yield 'airplane' => ["\u{2708}"];
        yield 'pencil' => ["\u{270F}"];
    }

    #[Test]
    #[DataProvider('textPresentationEmoji')]
    public function anEmojiThatTerminalsDrawAsTextTakesOneCell(string $character): void
    {
        self::assertSame(1, CharWidth::codepointWidth($character));
        self::assertSame(1, CharWidth::stringWidth($character));
    }

    #[Test]
    #[DataProvider('textPresentationEmoji')]
    public function theSameCharacterTakesTwoCellsWhenVariationSelector16AsksForEmoji(string $character): void
    {
        self::assertSame(2, CharWidth::stringWidth($character . "\u{FE0F}"));
    }

    #[Test]
    public function everyTextPresentationEmojiTakesTwoCellsWithVariationSelector16(): void
    {
        self::requireUnicode16Pcre();

        $narrow = [];
        for ($codepoint = 0x20; $codepoint <= 0x1FFFF; ++$codepoint) {
            $character = mb_chr($codepoint);
            if ($character === false
                || preg_match('/^\p{Emoji}$/u', $character) !== 1
                || preg_match('/^\p{Emoji_Presentation}$/u', $character) === 1
            ) {
                continue;
            }

            CharWidth::forgetMeasurements();
            if (CharWidth::stringWidth($character . "\u{FE0F}") !== 2) {
                $narrow[] = sprintf('U+%04X', $codepoint);
            }
        }

        self::assertSame([], $narrow);
    }

    #[Test]
    public function aCodepointIsWideExactlyWhenUnicodeCallsItWideOrFullwidth(): void
    {
        if (!class_exists(IntlChar::class) || IntlChar::getUnicodeVersion()[0] < 16) {
            self::markTestSkipped('needs ext-intl with Unicode 16 for East_Asian_Width');
        }

        $mismatches = [];
        for ($codepoint = 0x20; $codepoint <= 0x3FFFD; ++$codepoint) {
            $character = mb_chr($codepoint);
            if ($character === false || CharWidth::codepointWidth($character) === 0 || IntlChar::charAge($codepoint)[0] > 16
                || IntlChar::charType($codepoint) === IntlChar::CHAR_CATEGORY_UNASSIGNED) {
                continue;
            }

            $eastAsianWidth = IntlChar::getIntPropertyValue($codepoint, IntlChar::PROPERTY_EAST_ASIAN_WIDTH);
            $expected = $eastAsianWidth === IntlChar::EA_WIDE || $eastAsianWidth === IntlChar::EA_FULLWIDTH ? 2 : 1;
            if (CharWidth::codepointWidth($character) !== $expected) {
                $mismatches[] = sprintf('U+%04X', $codepoint);
            }
        }

        self::assertSame([], array_slice($mismatches, 0, 20), sprintf('%d codepoints measure wrong', count($mismatches)));
    }

    #[Test]
    public function aCodepointIsZeroWidthExactlyWhenUnicodeCallsItAMarkOrAFormatCharacter(): void
    {
        self::requireUnicode16Pcre();

        $mismatches = [];
        for ($codepoint = 0xA0; $codepoint <= 0xE01EF; ++$codepoint) {
            $character = mb_chr($codepoint);
            if ($character === false) {
                continue;
            }

            $ignorable = ($codepoint >= 0x2060 && $codepoint <= 0x206F) || ($codepoint >= 0xFFF0 && $codepoint <= 0xFFF8) || $codepoint >= 0xE0000;
            $zero = $codepoint !== 0xAD && preg_match('/^[\p{Mn}\p{Me}\p{Cf}\p{Zl}\p{Zp}]$/u', $character) === 1
                || $ignorable && preg_match('/^\p{Cn}$/u', $character) === 1;
            if ((CharWidth::codepointWidth($character) === 0) !== $zero) {
                $mismatches[] = sprintf('U+%04X', $codepoint);
            }
        }

        self::assertSame([], array_slice($mismatches, 0, 20), sprintf('%d codepoints measure wrong', count($mismatches)));
    }

    #[Test]
    public function aKeycapTakesTwoCells(): void
    {
        self::assertSame(2, CharWidth::stringWidth("1\u{FE0F}\u{20E3}"));
    }

    #[Test]
    public function variationSelector15KeepsAnEmojiInItsNarrowTextForm(): void
    {
        self::assertSame(1, CharWidth::stringWidth("\u{2714}\u{FE0E}"));
    }

    #[Test]
    public function variationSelector16DoesNotWidenSomethingThatIsNotAnEmoji(): void
    {
        self::assertSame(1, CharWidth::stringWidth("A\u{FE0F}"));
    }

    #[Test]
    public function anEmojiPresentationCharacterIsWideWithoutAnySelector(): void
    {
        self::assertSame(2, CharWidth::stringWidth("\u{2705}")); // ✅
        self::assertSame(2, CharWidth::stringWidth("\u{231A}")); // ⌚
        self::assertSame(2, CharWidth::stringWidth("\u{1F600}")); // 😀
    }

    #[Test]
    public function aTextPresentationEmojiDoesNotShiftTheColumnsAfterIt(): void
    {
        self::assertSame(
            CharWidth::stringWidth('| x | ok |'),
            CharWidth::stringWidth("| \u{2714} | ok |"),
        );
    }

    #[Test]
    public function stringWidthWithCjk(): void
    {
        self::assertSame(4, CharWidth::stringWidth("\u{4E16}\u{754C}")); // 世界
    }

    #[Test]
    public function ansiAwareWidthIgnoresOscPayload(): void
    {
        self::assertSame(2, CharWidth::ansiAwareWidth("\x1b]0;a very long window title\x07ab"));
    }

    #[Test]
    public function combiningMarkWidthIsZero(): void
    {
        self::assertSame(0, CharWidth::codepointWidth("\u{0300}"));
    }

    #[Test]
    public function stringWidthWithMixedContent(): void
    {
        self::assertSame(5, CharWidth::stringWidth('Hello'));
        self::assertSame(4, CharWidth::stringWidth("\u{4E2D}\u{6587}"));
        self::assertSame(7, CharWidth::stringWidth("Hi \u{4E2D}\u{6587}"));
    }

    // --- L53: empty string and null character return 0 ---

    #[Test]
    public function emptyStringIsZeroWidth(): void
    {
        self::assertSame(0, CharWidth::codepointWidth(''));
    }

    #[Test]
    public function anEmptyGraphemeIsZeroWidth(): void
    {
        self::assertSame(0, CharWidth::graphemeWidth(''));
    }

    #[Test]
    public function nullCharacterIsZeroWidth(): void
    {
        self::assertSame(0, CharWidth::codepointWidth("\0"));
    }

    // --- L56: invalid multibyte ord returns 1 (fallback) ---

    #[Test]
    public function invalidUtf8ByteReturnsFallbackWidth(): void
    {
        // mb_ord returns false for invalid UTF-8 sequences
        self::assertSame(1, CharWidth::codepointWidth("\xC0"));
    }

    #[Test]
    public function invalidUtf8ContinuationByteReturnsFallbackWidth(): void
    {
        self::assertSame(0, CharWidth::codepointWidth("\x80"));
        self::assertSame(0, CharWidth::codepointWidth("\x9F"));
    }

    // --- L58: printable ASCII range boundaries (0x20 to 0x7E) ---

    #[Test]
    public function spaceCharacterIsOneWidth(): void
    {
        // 0x20 — exact lower boundary of printable ASCII
        self::assertSame(1, CharWidth::codepointWidth(' '));
    }

    #[Test]
    public function tildeIsOneWidth(): void
    {
        // 0x7E — last char before 0x7F, upper boundary of printable ASCII
        self::assertSame(1, CharWidth::codepointWidth('~'));
    }

    #[Test]
    public function exclamationMarkIsOneWidth(): void
    {
        // 0x21 — one above the lower boundary
        self::assertSame(1, CharWidth::codepointWidth('!'));
    }

    // --- L59: control characters below 0x20 return 0 ---

    #[Test]
    public function controlCharacterBelowSpaceIsZeroWidth(): void
    {
        // 0x01 SOH
        self::assertSame(0, CharWidth::codepointWidth("\x01"));
    }

    #[Test]
    public function lastControlCharacterBeforeSpaceIsZeroWidth(): void
    {
        // 0x1F — exact boundary, one below 0x20
        self::assertSame(0, CharWidth::codepointWidth("\x1F"));
    }

    #[Test]
    public function escapeCharacterIsZeroWidth(): void
    {
        // 0x1B ESC — another control char
        self::assertSame(0, CharWidth::codepointWidth("\x1B"));
    }

    // --- L60: DEL (0x7F) returns 0 ---

    #[Test]
    public function deleteCharacterIsZeroWidth(): void
    {
        self::assertSame(0, CharWidth::codepointWidth("\x7F"));
    }

    // --- L58 boundary: 0x80 is NOT in printable ASCII, falls through to general lookup ---

    #[Test]
    public function firstNonAsciiCodepointIsOneWidth(): void
    {
        // 0x80 — just above ASCII range, not in any zero-width or wide table
        self::assertSame(0, CharWidth::codepointWidth("\u{0080}"));
        self::assertSame(0, CharWidth::codepointWidth("\u{009F}"));
    }

    // --- Zero-width range boundary tests (exercises inRanges binary search) ---

    #[Test]
    public function combiningMarkAtStartOfFirstRangeIsZeroWidth(): void
    {
        // 0x0300 — first codepoint of first ZERO_WIDTH range
        self::assertSame(0, CharWidth::codepointWidth("\u{0300}"));
    }

    #[Test]
    public function combiningMarkAtEndOfFirstRangeIsZeroWidth(): void
    {
        // 0x036F — last codepoint of first ZERO_WIDTH range
        self::assertSame(0, CharWidth::codepointWidth("\u{036F}"));
    }

    #[Test]
    public function codepointJustBeforeZeroWidthRangeIsNotZeroWidth(): void
    {
        // 0x02FF — just before the first ZERO_WIDTH range [0x0300, 0x036F]
        self::assertSame(1, CharWidth::codepointWidth("\u{02FF}"));
    }

    #[Test]
    public function codepointJustAfterZeroWidthRangeIsNotZeroWidth(): void
    {
        // 0x0370 — just after the first ZERO_WIDTH range [0x0300, 0x036F]
        self::assertSame(1, CharWidth::codepointWidth("\u{0370}"));
    }

    #[Test]
    public function zeroWidthSpaceIsZeroWidth(): void
    {
        // 0x200B — zero-width space, in ZERO_WIDTH table
        self::assertSame(0, CharWidth::codepointWidth("\u{200B}"));
    }

    #[Test]
    public function variationSelectorIsZeroWidth(): void
    {
        // 0xFE0F — variation selector 16, last of [0xFE00, 0xFE0F]
        self::assertSame(0, CharWidth::codepointWidth("\u{FE0F}"));
    }

    #[Test]
    public function softHyphenIsOneWidth(): void
    {
        // 0x00AD — not in any zero-width or wide range
        self::assertSame(1, CharWidth::codepointWidth("\u{00AD}"));
    }

    // --- Wide range boundary tests ---

    #[Test]
    public function hangulJamoStartIsDoubleWidth(): void
    {
        // 0x1100 — first codepoint of first WIDE range
        self::assertSame(2, CharWidth::codepointWidth("\u{1100}"));
    }

    #[Test]
    public function hangulJamoEndIsDoubleWidth(): void
    {
        // 0x115F — last codepoint of first WIDE range
        self::assertSame(2, CharWidth::codepointWidth("\u{115F}"));
    }

    #[Test]
    public function codepointJustBeforeWideRangeIsNotWide(): void
    {
        // 0x10FF — just before [0x1100, 0x115F]
        self::assertSame(1, CharWidth::codepointWidth("\u{10FF}"));
    }

    #[Test]
    public function codepointJustAfterFirstWideRangeIsNotWide(): void
    {
        // 0x1160 — just after [0x1100, 0x115F]
        self::assertSame(1, CharWidth::codepointWidth("\u{1160}"));
    }

    #[Test]
    public function fullwidthExclamationIsDoubleWidth(): void
    {
        // 0xFF01 — fullwidth exclamation mark, in WIDE table
        self::assertSame(2, CharWidth::codepointWidth("\u{FF01}"));
    }

    #[Test]
    public function lastWideRangeEndIsDoubleWidth(): void
    {
        // 0x3FFFD — last codepoint in the last WIDE range [0x30000, 0x3FFFD]
        self::assertSame(2, CharWidth::codepointWidth("\u{3FFFD}"));
    }

    #[Test]
    public function codepointAfterAllWideRangesIsOneWidth(): void
    {
        // 0x3FFFE — just after the last WIDE range
        self::assertSame(1, CharWidth::codepointWidth("\u{3FFFE}"));
    }

    #[Test]
    public function emojiIsDoubleWidth(): void
    {
        // 0x1F600 — grinning face, in [0x1F300, 0x1F64F]
        self::assertSame(2, CharWidth::codepointWidth("\u{1F600}"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emojiNewerThanTheTables(): iterable
    {
        yield 'trombone' => ["\u{1FA8A}"];
        yield 'treasure chest' => ["\u{1FA8E}"];
        yield 'hairy creature' => ["\u{1FAC8}"];
        yield 'orca' => ["\u{1FACD}"];
        yield 'distorted face' => ["\u{1FAEA}"];
        yield 'fight cloud' => ["\u{1FAEF}"];
        yield 'landslide' => ["\u{1F6D8}"];
        yield 'one still unassigned' => ["\u{1FA8B}"];
        yield 'one with a combining mark' => ["\u{1FA8B}\u{0301}"];
    }

    #[Test]
    public function aSkinToneOnATextDefaultEmojiMakesItWide(): void
    {
        CharWidth::forgetMeasurements();

        self::assertSame([2, 2, 2, 2], array_map(
            CharWidth::stringWidth(...),
            ["\u{261D}\u{1F3FD}", "\u{270C}\u{1F3FB}", "\u{270D}\u{1F3FF}", "\u{26F9}\u{1F3FD}"],
        ));
    }

    #[Test]
    public function aSkinToneAfterALetterTakesTwoCellsOfItsOwn(): void
    {
        CharWidth::forgetMeasurements();

        self::assertSame(3, CharWidth::stringWidth("a\u{1F3FD}"));
    }

    #[Test]
    public function aSkinToneAfterALetterBeyondAsciiAddsItsTwoCells(): void
    {
        CharWidth::forgetMeasurements();

        self::assertSame(3, CharWidth::stringWidth("\u{0100}\u{1F3FD}"));
    }

    #[Test]
    public function anUnassignedCodepointUnicodeSetsAsideAsIgnorableTakesNoCell(): void
    {
        self::assertSame([0, 0, 0, 0], array_map(
            CharWidth::codepointWidth(...),
            ["\u{2065}", "\u{FFF0}", "\u{FFF8}", "\u{E0FFF}"],
        ));
    }

    #[Test]
    #[DataProvider('emojiNewerThanTheTables')]
    public function anEmojiNewerThanTheTablesIsWide(string $emoji): void
    {
        CharWidth::forgetMeasurements();

        self::assertSame(2, CharWidth::stringWidth($emoji));
    }

    // --- inRanges binary search: mid-range hits to exercise all branches ---

    #[Test]
    public function codepointInMiddleOfWideTableIsDoubleWidth(): void
    {
        // 0x2E80 — CJK radical, in [0x2E80, 0x303E] which is roughly middle of WIDE
        self::assertSame(2, CharWidth::codepointWidth("\u{2E80}"));
    }

    #[Test]
    public function codepointBetweenWideRangesIsOneWidth(): void
    {
        // 0x231C — between [0x231A, 0x231B] and [0x2329, 0x232A] in WIDE table
        self::assertSame(1, CharWidth::codepointWidth("\u{231C}"));
    }

    #[Test]
    public function supplementaryPlaneZeroWidthIsZero(): void
    {
        // 0xE0001 — language tag, in ZERO_WIDTH [0xE0001, 0xE0001]
        self::assertSame(0, CharWidth::codepointWidth("\u{E0001}"));
    }

    #[Test]
    public function supplementaryPlaneWideIsTwo(): void
    {
        // 0x20000 — CJK Unified Ideograph Extension B start
        self::assertSame(2, CharWidth::codepointWidth("\u{20000}"));
    }

    // --- L92-100: Binary search edge cases ---

    #[Test]
    public function binarySearchFindsFirstElement(): void
    {
        // 0x0300 is in the first range of ZERO_WIDTH — lo=0, should match immediately
        self::assertSame(0, CharWidth::codepointWidth("\u{0300}"));
    }

    #[Test]
    public function binarySearchFindsLastElement(): void
    {
        // 0xE01EF is in the last range of ZERO_WIDTH — should require traversing to hi end
        self::assertSame(0, CharWidth::codepointWidth("\u{E01EF}"));
    }

    #[Test]
    public function binarySearchMissesBeforeAllRanges(): void
    {
        // 0x0200 — below all ZERO_WIDTH ranges, binary search should exhaust and return false
        // This codepoint is not in WIDE either, so width=1
        self::assertSame(1, CharWidth::codepointWidth("\u{0200}"));
    }

    #[Test]
    public function binarySearchMissesAfterAllRanges(): void
    {
        self::assertSame(1, CharWidth::codepointWidth("\u{E1000}"));
    }

    #[Test]
    public function binarySearchMissesBetweenRanges(): void
    {
        // 0x0370 — between ZERO_WIDTH ranges [0x0300,0x036F] and [0x0483,0x0489]
        self::assertSame(1, CharWidth::codepointWidth("\u{0370}"));
    }

    // --- ansiAwareWidth ---

    #[Test]
    public function ansiAwareWidthStripsCodesAndMeasures(): void
    {
        self::assertSame(5, CharWidth::ansiAwareWidth("\x1b[31mHello\x1b[0m"));
    }

    #[Test]
    public function ansiAwareWidthWithCjk(): void
    {
        self::assertSame(4, CharWidth::ansiAwareWidth("\x1b[1m\u{4E16}\u{754C}\x1b[0m"));
    }

    // --- Wide range at single-codepoint entries (exercises exact match in binary search) ---

    #[Test]
    public function watchEmojiIsDoubleWidth(): void
    {
        // 0x231A — start of [0x231A, 0x231B], a 2-element WIDE range
        self::assertSame(2, CharWidth::codepointWidth("\u{231A}"));
    }

    #[Test]
    public function hourglassEmojiIsDoubleWidth(): void
    {
        // 0x231B — end of [0x231A, 0x231B]
        self::assertSame(2, CharWidth::codepointWidth("\u{231B}"));
    }

    #[Test]
    public function mahjongTileIsDoubleWidth(): void
    {
        // 0x1F004 — single-codepoint WIDE range [0x1F004, 0x1F004]
        self::assertSame(2, CharWidth::codepointWidth("\u{1F004}"));
    }

    // --- inRanges with exactly one element above and below to stress $mid calculation ---

    #[Test]
    public function codepointBelowSingleElementWideRange(): void
    {
        // 0x1F003 — just below [0x1F004, 0x1F004]
        self::assertSame(1, CharWidth::codepointWidth("\u{1F003}"));
    }

    #[Test]
    public function codepointAboveSingleElementWideRange(): void
    {
        // 0x1F005 — just above [0x1F004, 0x1F004], below [0x1F0CF, 0x1F0CF]
        self::assertSame(1, CharWidth::codepointWidth("\u{1F005}"));
    }

    #[Test]
    public function binarySearchExactStartOfRange(): void
    {
        // 0x0300 is exactly ranges[$mid][0] for the first zero-width range
        // If < becomes <=, this would incorrectly go to hi branch
        self::assertSame(0, CharWidth::codepointWidth("\u{0300}"));
    }

    #[Test]
    public function binarySearchExactEndOfRange(): void
    {
        // 0x036F is exactly ranges[$mid][1] for the first zero-width range
        // If > becomes >=, this would incorrectly go to lo branch
        self::assertSame(0, CharWidth::codepointWidth("\u{036F}"));
    }

    #[Test]
    public function binarySearchLoEqualsHiMatch(): void
    {
        // 0xFEFF — BOM, single-codepoint zero-width range [0xFEFF, 0xFEFF]
        // When lo==hi, if <= becomes <, the loop won't execute and this will return 1 instead of 0
        self::assertSame(0, CharWidth::codepointWidth("\u{FEFF}"));
    }

    #[Test]
    public function binarySearchLoEqualsHiMiss(): void
    {
        // After narrowing, when lo==hi and the element doesn't match,
        // the loop must still check that single element
        // 0xFEFE — just before [0xFEFF, 0xFEFF], should NOT be zero-width
        self::assertSame(1, CharWidth::codepointWidth("\u{FEFE}"));
    }

    #[Test]
    public function binarySearchMidMinusOneUpdateIsCorrect(): void
    {
        // Test that hi = mid - 1 (not mid) by finding a value just below a range
        // that is found by the binary search as the mid element.
        // 0x0482 — just below [0x0483, 0x0489], the second zero-width range
        self::assertSame(1, CharWidth::codepointWidth("\u{0482}"));
    }

    #[Test]
    public function binarySearchMidPlusOneUpdateIsCorrect(): void
    {
        // Test that lo = mid + 1 (not mid) by finding a value just above a range
        // 0x048A — just above [0x0483, 0x0489]
        self::assertSame(1, CharWidth::codepointWidth("\u{048A}"));
    }

    #[Test]
    public function binarySearchShiftRightByOne(): void
    {
        // Verify the mid calculation uses >>1 (divide by 2).
        // With a large range table, incorrect >>0 (no divide) would cause out-of-bounds.
        // Test many different codepoints across both tables to exercise the full search.
        self::assertSame(0, CharWidth::codepointWidth("\u{E0020}")); // Near end of zero-width table
        self::assertSame(2, CharWidth::codepointWidth("\u{AC00}")); // Hangul syllable, middle of wide table
        self::assertSame(0, CharWidth::codepointWidth("\u{20D0}")); // Combining mark, mid-table
    }

    #[Test]
    public function emptyStringAndNullAreDifferentCases(): void
    {
        // Both return 0, but via || not &&. If || becomes &&, both must be true
        // which is impossible since '' !== "\0". So either branch alone must work.
        self::assertSame(0, CharWidth::codepointWidth(''));
        self::assertSame(0, CharWidth::codepointWidth("\0"));
    }

    #[Test]
    public function truncateToWidthAscii(): void
    {
        self::assertSame('hello', CharWidth::truncateToWidth('hello world', 5));
    }

    #[Test]
    public function truncateToWidthReturnsFullStringWhenFits(): void
    {
        self::assertSame('hello', CharWidth::truncateToWidth('hello', 10));
    }

    #[Test]
    public function truncateToWidthWideCharacters(): void
    {
        self::assertSame("\u{4F60}\u{597D}", CharWidth::truncateToWidth("\u{4F60}\u{597D}\u{4E16}\u{754C}", 5));
    }

    #[Test]
    public function truncateToWidthEmptyString(): void
    {
        self::assertSame('', CharWidth::truncateToWidth('', 10));
    }

    #[Test]
    public function truncateToWidthZeroWidth(): void
    {
        self::assertSame('', CharWidth::truncateToWidth('hello', 0));
    }

    #[Test]
    public function truncateToWidthMixedContent(): void
    {
        self::assertSame("a\u{4F60}", CharWidth::truncateToWidth("a\u{4F60}b", 3));
    }

    #[Test]
    public function truncateToWidthExactFit(): void
    {
        self::assertSame("\u{4F60}\u{597D}", CharWidth::truncateToWidth("\u{4F60}\u{597D}", 4));
    }

    #[Test]
    public function asciiLowerBoundary0x20IsWidth1(): void
    {
        // Space 0x20 hits the $ord >= 0x20 && $ord < 0x7F fast path
        self::assertSame(1, CharWidth::codepointWidth("\x20"));
    }

    #[Test]
    public function charJustBefore0x7FIs1Not0(): void
    {
        // 0x7E (~) should be width 1 from the fast path, not 0
        // If the fast path boundary were $ord < 0x7E instead of 0x7F, ~ would miss
        self::assertSame(1, CharWidth::codepointWidth("\x7E"));
    }

    #[Test]
    public function truncateToWidthNegativeWidthReturnsEmpty(): void
    {
        self::assertSame('', CharWidth::truncateToWidth('abc', -1));
    }

    #[Test]
    public function truncateToWidthZeroWidthWithZeroWidthCharsReturnsEmpty(): void
    {
        self::assertSame('', CharWidth::truncateToWidth("\u{0300}abc", 0));
    }

    #[Test]
    public function truncateToWidthStopsExactlyAtBoundary(): void
    {
        self::assertSame('abc', CharWidth::truncateToWidth('abcdef', 3));
    }

    #[Test]
    public function truncateToWidthDoesNotIncludeCharExceedingBoundary(): void
    {
        self::assertSame('ab', CharWidth::truncateToWidth("ab\u{4E16}cd", 3));
    }

    #[Test]
    public function truncateToWidthKeepsAZwjEmojiGraphemeIntact(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";

        self::assertSame($family, CharWidth::truncateToWidth($family . 'x', 2));
    }

    #[Test]
    public function truncateToWidthKeepsAFlagGraphemeIntact(): void
    {
        $flag = "\u{1F1FA}\u{1F1F8}";

        self::assertSame($flag, CharWidth::truncateToWidth($flag . 'x', 2));
    }

    #[Test]
    public function graphemeWidthOfAC1ControlByteIsZero(): void
    {
        // Not valid UTF-8 on its own, and a terminal prints nothing for it.
        self::assertSame(0, CharWidth::graphemeWidth("\x80"));
        self::assertSame(0, CharWidth::graphemeWidth("\x9F"));
    }

    #[Test]
    public function graphemeWidthOfOtherInvalidBytesIsOne(): void
    {
        self::assertSame(1, CharWidth::graphemeWidth("\xFF"), 'Just outside the C1 range');
        self::assertSame(1, CharWidth::graphemeWidth("\xC3"), 'A lone lead byte occupies a column');
    }

    #[Test]
    public function graphemeWidthCombiningMarkIsOne(): void
    {
        self::assertSame(1, CharWidth::graphemeWidth("e\u{0301}"));
    }

    #[Test]
    public function graphemeWidthEmojiZwjIsTwo(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
        self::assertSame(2, CharWidth::graphemeWidth($family));
    }

    #[Test]
    public function aWideIdeographIsTwoCellsAsAGrapheme(): void
    {
        self::assertSame(2, CharWidth::graphemeWidth("\u{4E16}"));
    }

    #[Test]
    public function aLoneCombiningMarkIsAZeroWidthGrapheme(): void
    {
        self::assertSame(0, CharWidth::graphemeWidth("\u{0300}"));
    }

    #[Test]
    public function clustersSharingEveryByteButTheLastAreMeasuredApart(): void
    {
        self::assertSame(1, CharWidth::graphemeWidth("\u{2714}"));
        self::assertSame(2, CharWidth::graphemeWidth("\u{2714}\u{FE0F}"));
        self::assertSame(1, CharWidth::graphemeWidth("\u{2714}\u{FE0E}"));
    }

    #[Test]
    public function aClusterIsMeasuredApartFromTheOneItExtends(): void
    {
        self::assertSame(2, CharWidth::graphemeWidth("\u{270D}\u{FE0F}"));
        self::assertSame(1, CharWidth::graphemeWidth("\u{270D}"));
        self::assertSame(1, CharWidth::graphemeWidth('e'));
        self::assertSame(1, CharWidth::graphemeWidth("e\u{0301}"));
    }

    #[Test]
    public function stringWidthWithCombiningMarkIsFour(): void
    {
        self::assertSame(4, CharWidth::stringWidth("cafe\u{0301}"));
    }

    #[Test]
    public function stringWidthFlagEmojiIsTwo(): void
    {
        self::assertSame(2, CharWidth::stringWidth("\u{1F1FA}\u{1F1F8}"));
    }

    #[Test]
    public function stringWidthPureAsciiUsesStrlen(): void
    {
        self::assertSame(11, CharWidth::stringWidth('Hello World'));
    }

    #[Test]
    public function measuringMoreDistinctClustersThanTheBudgetLeavesTheMemoWithinIt(): void
    {
        for ($codepoint = 0x4E00; $codepoint < 0x4E00 + 5_000; ++$codepoint) {
            CharWidth::graphemeWidth(mb_chr($codepoint, 'UTF-8'));
        }

        $remembered = self::rememberedWidthCount();

        self::assertGreaterThan(0, $remembered, 'A memo that keeps nothing is not a memo');
        self::assertLessThanOrEqual(4_096, $remembered, 'A long-running TUI would leak every cluster it ever drew');
    }

    protected function setUp(): void
    {
        CharWidth::forgetMeasurements();
    }

    private static function rememberedWidthCount(): int
    {
        $memo = (new ReflectionProperty(CharWidth::class, 'graphemeWidths'))->getValue();
        self::assertIsArray($memo);

        return count($memo);
    }

    /**
     * The width tables are Unicode 16; PCRE2 knows Unicode 16 from 10.45.
     */
    private static function requireUnicode16Pcre(): void
    {
        if (version_compare(explode(' ', PCRE_VERSION)[0], '10.45', '<')) {
            self::markTestSkipped('this PCRE predates Unicode 16');
        }
    }
}
