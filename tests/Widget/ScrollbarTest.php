<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollbarTest extends TestCase
{
    private int $thumbColor;
    private int $trackColor;

    #[Test]
    public function drawsDownTheRightHandColumnOfTheTrackItIsGiven(): void
    {
        $frame = new Frame(6, 4);

        Scrollbar::renderFor($frame, new Rect(0, 0, 6, 4), 0, 40, Theme::dark());

        $marks = [Scrollbar::THUMB, Scrollbar::TRACK];
        for ($row = 0; $row < 4; ++$row) {
            self::assertContains($frame->characters[$row * 6 + 5], $marks, "The bar occupies the rightmost column on row $row");
            self::assertNotContains($frame->characters[$row * 6], $marks, "and nothing else on row $row");
        }
    }

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

    #[Test]
    public function thumbSizeMinimumIsExactlyOne(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 0, 100, 1, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        self::assertCount(1, $layout['thumbRows'], 'Thumb size must be exactly 1 for tiny visible/total ratio');
    }

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

    #[Test]
    public function thumbPositionAtMidScroll(): void
    {
        $frame = new Frame(1, 20);
        Scrollbar::render($frame, 0, 0, 20, 15, 40, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 20);
        // thumbSize=5, thumbPosition=7, thumb at rows 7-11
        self::assertSame([7, 8, 9, 10, 11], $layout['thumbRows']);
    }

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

    #[Test]
    public function thumbPositionUsesHeightMinusThumbSize(): void
    {
        $frame = new Frame(1, 10);
        Scrollbar::render($frame, 0, 0, 10, 5, 20, 10, $this->thumbColor, $this->trackColor);

        $layout = $this->extractScrollbarLayout($frame, 0, 10);
        // thumbSize=5, thumbPosition=2, thumb at rows 2-6
        self::assertSame([2, 3, 4, 5, 6], $layout['thumbRows']);
    }

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
        $this->trackColor = Color::ansi(AnsiColor::BrightBlack)->value;
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
