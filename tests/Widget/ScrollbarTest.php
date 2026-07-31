<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Scrollbar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollbarTest extends TestCase
{
    private int $thumbColor;
    private int $trackColor;

    #[Test]
    public function rendersTrackAndThumb(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 30, 10, $this->thumbColor, $this->trackColor);

        $thumbSize = max(1, (int)floor(10 * 10 / 30));
        $thumbCount = 0;
        $trackCount = 0;

        for ($row = 0; $row < 10; ++$row) {
            $index = $row * $frame->width;
            if ($frame->characters[$index] === Scrollbar::THUMB) {
                ++$thumbCount;
                self::assertSame($this->thumbColor, $frame->foregrounds[$index]);
            } elseif ($frame->characters[$index] === Scrollbar::TRACK) {
                ++$trackCount;
                self::assertSame($this->trackColor, $frame->foregrounds[$index]);
            }
        }

        self::assertSame($thumbSize, $thumbCount);
        self::assertSame(10 - $thumbSize, $trackCount);
        self::assertSame(Scrollbar::THUMB, $frame->characters[0], 'Thumb should start at row 0 when offset is 0');
    }

    #[Test]
    public function thumbMovesWithOffset(): void
    {
        $frame = new Frame(1, 10);
        $totalContent = 30;
        $visibleHeight = 10;
        $maxOffset = $totalContent - $visibleHeight;

        Scrollbar::render($frame, 0, 0, 10, $maxOffset, $totalContent, $visibleHeight, $this->thumbColor, $this->trackColor);

        $thumbSize = max(1, (int)floor($visibleHeight * 10 / $totalContent));
        $lastThumbRow = 9;
        $firstThumbRow = 10 - $thumbSize;

        for ($row = $firstThumbRow; $row <= $lastThumbRow; ++$row) {
            $index = $row * $frame->width;
            self::assertSame(Scrollbar::THUMB, $frame->characters[$index], "Row $row should be thumb at max offset");
        }

        for ($row = 0; $row < $firstThumbRow; ++$row) {
            $index = $row * $frame->width;
            self::assertSame(Scrollbar::TRACK, $frame->characters[$index], "Row $row should be track at max offset");
        }
    }

    #[Test]
    public function noRenderWhenContentFits(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 5, 10, $this->thumbColor, $this->trackColor);

        for ($row = 0; $row < 10; ++$row) {
            $index = $row * $frame->width;
            self::assertSame(' ', $frame->characters[$index], 'Buffer should remain unchanged when content fits');
            self::assertSame(0, $frame->foregrounds[$index]);
        }
    }

    #[Test]
    public function thumbMinimumSizeIsOne(): void
    {
        $frame = new Frame(1, 5);
        Scrollbar::render($frame, 0, 0, 5, 0, 1000, 5, $this->thumbColor, $this->trackColor);

        $thumbCount = 0;
        for ($row = 0; $row < 5; ++$row) {
            $index = $row * $frame->width;
            if ($frame->characters[$index] === Scrollbar::THUMB) {
                ++$thumbCount;
            }
        }

        self::assertGreaterThanOrEqual(1, $thumbCount, 'Thumb size must be at least 1');
    }

    #[Test]
    public function needsScrollbar(): void
    {
        self::assertTrue(Scrollbar::needsScrollbar(20, 10));
        self::assertFalse(Scrollbar::needsScrollbar(10, 10));
        self::assertFalse(Scrollbar::needsScrollbar(5, 10));
    }

    // --- Mutant-killing tests below ---

    /**
     * Kills L26 DecrementInteger/IncrementInteger:
     * Default background parameter must be exactly 0, not -1 or 1.
     */
    #[Test]
    public function defaultBackgroundColorIsZero(): void
    {
        $frame = new Frame(1, 5);
        Scrollbar::render($frame, 0, 0, 5, 0, 20, 5, $this->thumbColor, $this->trackColor);

        for ($row = 0; $row < 5; ++$row) {
            $index = $row * $frame->width;
            self::assertSame(0, $frame->backgrounds[$index], "Row $row background should be 0 (default)");
        }
    }

    /**
     * Kills L28 LessThanOrEqualTo (<= to <):
     * Height of exactly 0 should produce no rendering.
     * With the mutant (< 0), height=0 would pass the guard but produce no loop iterations.
     * We test that no buffer mutation occurs.
     */
    #[Test]
    public function noRenderWhenHeightIsZero(): void
    {
        $frame = new Frame(1, 5);
        Scrollbar::render($frame, 0, 0, 0, 0, 20, 5, $this->thumbColor, $this->trackColor);

        for ($row = 0; $row < 5; ++$row) {
            $index = $row * $frame->width;
            self::assertSame(' ', $frame->characters[$index], 'Buffer unchanged with height=0');
            self::assertSame(0, $frame->foregrounds[$index]);
        }
    }

    /**
     * Kills L32 IncrementInteger (max(1,...) → max(2,...)):
     * With content=20, visible=10, height=10: thumbSize = floor(10*10/20) = 5.
     * If mutant changes max(1,...) to max(2,...), thumbSize stays 5 (no change for this case).
     * We need a case where floor gives exactly 1: content=100, visible=1, height=10:
     * floor(1*10/100) = floor(0.1) = 0 → max(1,0) = 1. Mutant: max(2,0) = 2.
     */
    #[Test]
    public function thumbSizeMinimumIsExactlyOne(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 100, 1, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertCount(1, $layout['thumbRows'], 'Thumb size must be exactly 1 for tiny visible/total ratio');
    }

    /**
     * Kills L32 RoundingFamily (floor→ceil or round):
     * content=30, visible=10, height=10: floor(10*10/30) = floor(3.33) = 3.
     * ceil(3.33) = 4, round(3.33) = 3 (round survives here).
     * content=7, visible=3, height=10: floor(3*10/7) = floor(4.28) = 4.
     * ceil(4.28) = 5.
     * round(4.28) = 4 (still survives).
     * content=7, visible=5, height=10: floor(5*10/7) = floor(7.14) = 7.
     * ceil(7.14) = 8, round(7.14) = 7.
     * For round: content=3, visible=2, height=10: floor(2*10/3)=floor(6.66)=6. round=7, ceil=7.
     */
    #[Test]
    public function thumbSizeUsesFloorNotCeil(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 30, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertCount(3, $layout['thumbRows'], 'floor(10*10/30) = 3, not ceil(3.33)=4');
    }

    #[Test]
    public function thumbSizeUsesFloorNotRound(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 3, 2, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        // floor(2*10/3) = floor(6.666) = 6, round(6.666) = 7
        self::assertCount(6, $layout['thumbRows'], 'floor(2*10/3) = 6, not round(6.66)=7');
    }

    /**
     * Kills L33 DecrementInteger (max(1,...) → max(0,...)):
     * When totalContent == visibleHeight + 1, maxOffset = max(1, 1) = 1.
     * Mutant: max(0, 1) = 1 — same. Need totalContent == visibleHeight:
     * But needsScrollbar returns false if totalContent <= visibleHeight. So maxOffset = totalContent - visibleHeight >= 1.
     * Actually, we can't reach maxOffset < 1 because needsScrollbar guards that totalContent > visibleHeight,
     * meaning totalContent - visibleHeight >= 1. The max(1,...) is a safety net.
     * However the mutant max(0,...) would make maxOffset=0 if totalContent == visibleHeight,
     * but that path is unreachable. For totalContent = visibleHeight + 1, maxOffset = 1 either way.
     * This mutant might be equivalent given the guard. Let me try to find a case...
     * Actually it IS reachable if somehow the values align. totalContent - visibleHeight is always >= 1
     * when we get past the guard. So max(1, x>=1) = x, and max(0, x>=1) = x. Equivalent mutant.
     *
     * Kills L33 IncrementInteger (max(1,...) → max(2,...)):
     * totalContent = visibleHeight + 1 → maxOffset = max(1, 1) = 1 vs max(2, 1) = 2.
     * With scrollOffset=1 (at max), height=10, thumbSize: floor(vis*10/(vis+1)).
     * Let vis=10, total=11: thumbSize = floor(10*10/11) = floor(9.09) = 9.
     * thumbPosition = floor(1/1 * (10-9)) = floor(1) = 1. Mutant: floor(1/2 * 1) = floor(0.5) = 0.
     * So thumb starts at row 1 normally, row 0 with mutant.
     */
    #[Test]
    public function maxOffsetIsOneWhenContentExceedsByOne(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 1, 11, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        // thumbSize = floor(10*10/11) = 9
        // thumbPosition = floor(1/1 * (10-9)) = 1
        // So row 0 should be track, rows 1-9 should be thumb
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $layout['thumbRows'], 'Thumb should start at row 1 with offset=1, total=11, visible=10');
        self::assertSame([0], $layout['trackRows'], 'Only row 0 should be track');
    }

    /**
     * Kills L34 Division (/ to *), Minus (- to +), CastInt, RoundingFamily x2:
     * Test thumb position at a mid-scroll offset.
     * total=40, visible=10, height=20, offset=15.
     * thumbSize = floor(10*20/40) = floor(5) = 5.
     * maxOffset = max(1, 40-10) = 30.
     * thumbPosition = floor(15/30 * (20-5)) = floor(0.5 * 15) = floor(7.5) = 7.
     *
     * Mutant Division (/ to *): floor(15*30 * 15) = huge → clamped to max(0, min(15, huge)) = 15. Fails: 15 != 7.
     * Mutant Minus (- to +): floor(15/30 * (20+5)) = floor(0.5*25) = floor(12.5) = 12. Fails: 12 != 7.
     * Mutant CastInt removed: in PHP, floor returns float. Without (int), it's still usable as numeric.
     *   But we compare with assertSame which checks type. Actually the clamping uses max/min which work on floats.
     *   The for-loop comparison $row >= $thumbPosition works fine with float. Need another approach.
     * Mutant RoundingFamily: ceil(7.5) = 8, round(7.5) = 8. Both give 8 != 7.
     */
    #[Test]
    public function thumbPositionAtMidScroll(): void
    {
        $frame = new Frame(1, 20);
        Scrollbar::render($frame, 0, 0, 20, 15, 40, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 20);
        // thumbSize=5, thumbPosition=7, thumb at rows 7-11
        self::assertSame([7, 8, 9, 10, 11], $layout['thumbRows']);
    }

    /**
     * Additional test for L34 CastInt removal:
     * Without (int) cast, floor() returns float. The comparison in the for loop
     * ($row >= $thumbPosition) would still work with float, so we need to verify
     * the exact thumb position with a fractional result.
     * total=50, visible=10, height=20, offset=10.
     * thumbSize = floor(10*20/50) = floor(4) = 4.
     * maxOffset = max(1, 50-10) = 40.
     * thumbPosition = (int)floor(10/40 * (20-4)) = (int)floor(0.25 * 16) = (int)floor(4.0) = 4.
     * Need a case where floor gives non-integer-like float... floor always gives integer-valued float.
     * So (int) cast on floor() result is essentially a no-op for normal values.
     * The CastInt mutant might be equivalent. Let me verify with edge values.
     * Actually, for very large values, float precision could matter, but practically it's equivalent.
     * Let me still write a position test that pins the behavior.
     */
    #[Test]
    public function thumbPositionWithFractionalCalculation(): void
    {
        $frame = new Frame(1, 10);
        // total=25, visible=5, height=10, offset=10
        // thumbSize = floor(5*10/25) = floor(2) = 2
        // maxOffset = max(1, 25-5) = 20
        // thumbPosition = (int)floor(10/20 * (10-2)) = (int)floor(0.5 * 8) = (int)floor(4.0) = 4
        Scrollbar::render($frame, 0, 0, 10, 10, 25, 5, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertSame([4, 5], $layout['thumbRows']);
    }

    /**
     * Kills L35 DecrementInteger (max(0,...) → max(-1,...)):
     * We need thumbPosition to be negative before clamping.
     * At offset=0: thumbPosition = floor(0/maxOffset * ...) = 0.
     * max(0, ...) = 0, max(-1, ...) = 0. Same.
     * Actually, when thumbPosition is computed as 0 and clamped, both max(0,...) and max(-1,...) give 0.
     * We need a case where thumbPosition would be -1 without the clamp, but that can't happen since
     * scrollOffset >= 0, maxOffset >= 1, (height-thumbSize) >= 0 (since thumbSize <= height in practice).
     * This might be equivalent, but let me verify with offset=0 that thumb starts exactly at 0.
     */
    #[Test]
    public function thumbStartsAtRowZeroWithZeroOffset(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 20, 5, $this->thumbColor, $this->trackColor);

        // thumbSize = floor(5*10/20) = 2
        // thumbPosition = floor(0/15 * 8) = 0
        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertSame(0, $layout['thumbRows'][0], 'Thumb must start at row 0 when scroll offset is 0');
        self::assertCount(2, $layout['thumbRows']);
    }

    /**
     * Kills L35 Minus (- to +) in min($height - $thumbSize, ...):
     * Mutant: min($height + $thumbSize, $thumbPosition) — clamp upper bound becomes height+thumbSize instead of height-thumbSize.
     * total=20, visible=10, height=10, offset=10 (max).
     * thumbSize = floor(10*10/20) = 5.
     * maxOffset = 10.
     * thumbPosition (raw) = floor(10/10 * (10-5)) = floor(5) = 5.
     * Clamp: max(0, min(10-5, 5)) = max(0, min(5, 5)) = 5.
     * Mutant: max(0, min(10+5, 5)) = max(0, min(15, 5)) = 5. Same!
     * Need a case where raw thumbPosition > height-thumbSize.
     * offset=maxOffset, thumbPosition_raw = floor(1 * (height-thumbSize)) = height-thumbSize. Always clamped same.
     * Actually the clamp never triggers for valid inputs. Let me try offset > maxOffset (invalid but possible).
     * No, the code doesn't guard against that.
     * Let's try: total=30, visible=10, height=10, offset=25 (beyond max of 20).
     * thumbSize = floor(10*10/30) = 3.
     * maxOffset = max(1, 20) = 20.
     * thumbPosition_raw = floor(25/20 * (10-3)) = floor(1.25 * 7) = floor(8.75) = 8.
     * Clamp: max(0, min(10-3, 8)) = max(0, min(7, 8)) = 7. Thumb at rows 7,8,9.
     * Mutant: max(0, min(10+3, 8)) = max(0, min(13, 8)) = 8. Thumb at rows 8,9,10 — but row 10 is out of buffer height 10!
     */
    #[Test]
    public function thumbClampedToBottomWhenOffsetExceedsMax(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 25, 30, 10, $this->thumbColor, $this->trackColor);

        // thumbSize=3, raw thumbPos=8, clamped to 7
        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertSame([7, 8, 9], $layout['thumbRows'], 'Thumb must be clamped to bottom of scrollbar area');
        self::assertCount(7, $layout['trackRows']);
    }

    /**
     * Kills L37 DecrementInteger (0 → -1 in for loop init):
     * If loop starts at row=-1, putChar(-1 offset) would write one extra row.
     * But putChar guards against y < 0 when y + row < 0. If y=0, row=-1 gives y=-1 → out of bounds, no write.
     * If y=1, row=-1 gives y=0 → writes to row 0 which shouldn't have scrollbar content.
     * Let's use y=1 to detect this.
     */
    #[Test]
    public function loopStartsAtRowZeroNotNegativeOne(): void
    {
        $frame = new Frame(1, 7);
        // Render scrollbar at y=1 with height=5, so rows 1-5 should be filled
        Scrollbar::render($frame, 0, 1, 5, 0, 20, 5, $this->thumbColor, $this->trackColor);

        // Row 0 (y=0) must remain untouched
        self::assertSame(' ', $frame->characters[0], 'Row before scrollbar start must not be touched');
        self::assertSame(0, $frame->foregrounds[0]);

        // Row 6 (y=6) must also remain untouched
        self::assertSame(' ', $frame->characters[6], 'Row after scrollbar end must not be touched');
        self::assertSame(0, $frame->foregrounds[6]);

        // Rows 1-5 should have scrollbar content
        for ($row = 1; $row <= 5; ++$row) {
            self::assertContains($frame->characters[$row], [Scrollbar::THUMB, Scrollbar::TRACK], "Row $row should have scrollbar content");
        }
    }

    /**
     * Kills L37 LessThan (< to <=):
     * If loop uses $row <= $height instead of $row < $height, it renders one extra row.
     * With height=5 at y=0 in a buffer of height 6, the mutant would write to row 5.
     */
    #[Test]
    public function loopRendersExactlyHeightRows(): void
    {
        $frame = new Frame(1, 8);
        Scrollbar::render($frame, 0, 0, 5, 0, 20, 5, $this->thumbColor, $this->trackColor);

        $renderedCount = 0;
        for ($row = 0; $row < 8; ++$row) {
            if ($frame->characters[$row] !== ' ') {
                ++$renderedCount;
            }
        }

        self::assertSame(5, $renderedCount, 'Scrollbar must render exactly height (5) rows, not more');
        // Row 5 specifically must be empty
        self::assertSame(' ', $frame->characters[5], 'Row at index height must not be rendered');
        self::assertSame(0, $frame->foregrounds[5]);
    }

    /**
     * Kills L34 RoundingFamily more precisely:
     * total=40, visible=10, height=20, offset=7.
     * thumbSize = floor(10*20/40) = 5.
     * maxOffset = 30.
     * thumbPosition = (int)floor(7/30 * 15) = (int)floor(3.5) = 3.
     * ceil(3.5) = 4, round(3.5) = 4 (PHP rounds 0.5 up). Both differ from floor.
     */
    #[Test]
    public function thumbPositionUsesFloorRounding(): void
    {
        $frame = new Frame(1, 20);
        Scrollbar::render($frame, 0, 0, 20, 7, 40, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 20);
        // thumbSize=5 starting at position 3 → rows 3,4,5,6,7
        self::assertSame(3, $layout['thumbRows'][0], 'Thumb position must use floor, not ceil/round');
        self::assertCount(5, $layout['thumbRows']);
    }

    /**
     * Kills L34 Minus (- to +) more directly:
     * height - thumbSize vs height + thumbSize in thumb position calc.
     * total=20, visible=10, height=10, offset=5.
     * thumbSize = floor(10*10/20) = 5.
     * maxOffset = 10.
     * Normal: floor(5/10 * (10-5)) = floor(0.5*5) = floor(2.5) = 2.
     * Mutant: floor(5/10 * (10+5)) = floor(0.5*15) = floor(7.5) = 7.
     */
    #[Test]
    public function thumbPositionUsesHeightMinusThumbSize(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 5, 20, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        // thumbSize=5, thumbPosition=2, thumb at rows 2-6
        self::assertSame([2, 3, 4, 5, 6], $layout['thumbRows']);
    }

    /**
     * Kills L34 Division (/ to *) more directly:
     * With the mutant, scrollOffset * maxOffset produces a huge number, clamped to max.
     * total=20, visible=10, height=10, offset=5.
     * Normal: floor(5/10 * 5) = 2.
     * Mutant: floor(5*10 * 5) = 250, clamped to min(5, 250) = 5.
     */
    #[Test]
    public function thumbPositionUsesDivisionNotMultiplication(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 5, 20, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        // With division: thumb at rows 2-6. With multiplication: thumb at rows 5-9.
        self::assertNotSame(5, $layout['thumbRows'][0], 'Thumb position must not be at bottom (division, not multiplication)');
        self::assertSame(2, $layout['thumbRows'][0]);
    }

    /**
     * Comprehensive test that covers the full scrollbar rendering pipeline.
     * Uses explicit background color to verify L26.
     */
    #[Test]
    public function explicitBackgroundColorIsUsed(): void
    {
        $frame = new Frame(1, 5);
        $customBackground = Color::rgb(30, 30, 30)->value;
        Scrollbar::render($frame, 0, 0, 5, 0, 20, 5, $this->thumbColor, $this->trackColor, $customBackground);

        for ($row = 0; $row < 5; ++$row) {
            $index = $row * $frame->width;
            self::assertSame($customBackground, $frame->backgrounds[$index], "Row $row should use explicit background color");
        }
    }

    /**
     * Kills L33 equivalence more aggressively:
     * Test that maxOffset calculation gives correct thumb position for content barely exceeding visible.
     * total=11, visible=10, offset=0.
     * maxOffset = max(1, 1) = 1.
     * thumbSize = floor(10*10/11) = 9.
     * thumbPosition = floor(0/1 * (10-9)) = 0.
     * Thumb at rows 0-8, track at row 9.
     */
    #[Test]
    public function scrollbarWithContentBarellyExceedingVisible(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 11, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertCount(9, $layout['thumbRows']);
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8], $layout['thumbRows']);
        self::assertSame([9], $layout['trackRows']);
    }

    #[Test]
    public function fillRectsWriteOnlyOneColumn(): void
    {
        // L43, L46, L49 IncrementInteger: width=1 → 2. Use a 2-col buffer and verify col 1 is untouched.
        $frame = new Frame(2, 10);
        for ($i = 0; $i < 20; ++$i) {
            $frame->putChar($i % 2, intdiv($i, 2), '.');
        }
        Scrollbar::render($frame, 0, 0, 10, 5, 30, 10, $this->thumbColor, $this->trackColor);

        for ($row = 0; $row < 10; ++$row) {
            $colOne = $row * 2 + 1;
            self::assertSame('.', $frame->characters[$colOne], "Scrollbar column 1 row {$row} must remain untouched (width must be 1)");
        }
    }

    #[Test]
    public function fillRectsWriteNoAttributes(): void
    {
        // L43, L46, L49 DecrementInteger/IncrementInteger: attributes parameter must be 0
        // Render with all three fillRects exercised: track-above (thumbPos > 0), thumb (thumbSize > 0), track-below (afterThumb < height).
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 5, 30, 10, $this->thumbColor, $this->trackColor);

        for ($row = 0; $row < 10; ++$row) {
            self::assertSame(0, $frame->attributes[$row], "Scrollbar row {$row} must have no text attributes");
        }
    }

    #[Test]
    public function theScrollbarGlyphsAreNotBoxDrawingCharacters(): void
    {
        // A scrollbar drawn with border glyphs reads as a doubled pane border.
        foreach (['│', '┃', '║', '╎'] as $borderGlyph) {
            self::assertNotSame($borderGlyph, Scrollbar::THUMB);
            self::assertNotSame($borderGlyph, Scrollbar::TRACK);
        }
    }

    #[Test]
    public function theThumbIsHeavierThanTheTrack(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 40, 10, 1, 2, 0);

        self::assertSame(Scrollbar::THUMB, $frame->characters[0]);
        self::assertSame(Scrollbar::TRACK, $frame->characters[9]);
    }

    protected function setUp(): void
    {
        $this->thumbColor = Color::hex('FF06B7')->value;
        $this->trackColor = Color::ansi(8)->value;
    }

    /**
     * @return array{thumbRows: list<int>, trackRows: list<int>}
     */
    private function extractScrollbarLayout(Frame $frame, int $x, int $height, int $yOffset = 0): array
    {
        $thumbRows = [];
        $trackRows = [];
        for ($row = 0; $row < $height; ++$row) {
            $index = ($yOffset + $row) * $frame->width + $x;
            if ($frame->characters[$index] === Scrollbar::THUMB) {
                $thumbRows[] = $row;
            } elseif ($frame->characters[$index] === Scrollbar::TRACK) {
                $trackRows[] = $row;
            }
        }

        return ['thumbRows' => $thumbRows, 'trackRows' => $trackRows];
    }
}
