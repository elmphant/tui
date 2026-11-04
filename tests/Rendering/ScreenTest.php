<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Terminal\TerminalOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScreenTest extends TestCase
{
    #[Test]
    public function aWideEmojiThatWidensOnItsSelectorClearsItsTwoCellsFirst(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "a❤\u{FE0F}c");

        $screen->flush($frame);

        self::assertStringContainsString("a  \x1b[1;2H❤\u{FE0F}\x1b[1;4Hc", $capturedOutput);
    }

    #[Test]
    public function aClusterTerminalsMeasureDifferentlyLeavesTheCursorWhereTheFrameSaysItEnds(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "👩\u{200D}👧x");

        $screen->flush($frame);

        self::assertStringContainsString("👩\u{200D}👧\x1b[1;3Hx", $capturedOutput);
    }

    #[Test]
    public function aNarrowClusterTerminalsMayDrawWideLeavesTheCursorWhereTheFrameSaysItEnds(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{1F441}\u{200D}\u{1F5E8}x");

        $screen->flush($frame);

        self::assertStringContainsString("\u{1F441}\u{200D}\u{1F5E8}\x1b[1;2Hx", $capturedOutput);
    }

    #[Test]
    public function aClusterDrawnAgainRedrawsTheRestOfItsRowThatItMayHaveCovered(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(6, 1);
        $frame->putString(0, 0, "👩\u{200D}👧ab|");
        $screen->flush($frame);
        $capturedOutput = '';

        $frame->putString(0, 0, "👨\u{200D}👧");
        $screen->flush($frame);

        self::assertStringContainsString("👨\u{200D}👧\x1b[1;3Hab|", $capturedOutput);
    }

    #[Test]
    public function aFullRedrawClearsEachRowBeforeWritingIt(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));

        $screen->flush(new Frame(4, 3));

        self::assertSame(3, substr_count($capturedOutput, Ansi::ERASE_TO_LINE_END));
    }

    #[Test]
    public function aWideEmojiDrawnOverAnOldWideCharacterClearsItsTwoCellsFirst(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'a漢漢');
        $screen->flush($frame);
        $capturedOutput = '';

        $frame->putString(1, 0, "❤\u{FE0F}c");
        $screen->flush($frame);

        self::assertStringContainsString("  \x1b[1;2H❤\u{FE0F}\x1b[1;4Hc", $capturedOutput);
    }

    #[Test]
    public function aWideCharacterIsEmittedOnceNotTwice(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        // A CJK glyph occupies two columns, so the cell after it holds no
        // character of its own and must not be emitted as a blank.
        $frame = new Frame(6, 1);
        $frame->putString(0, 0, '你好ab');

        $screen->flush($frame);

        self::assertSame(1, substr_count($capturedOutput, '你'));
        self::assertSame(1, substr_count($capturedOutput, '好'));
        self::assertStringContainsString('你好ab', $capturedOutput, 'Emitted as one run, with no gap');
    }

    #[Test]
    public function aWideCharacterSurvivesADiffFlush(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $first = new Frame(6, 1);
        $first->putString(0, 0, '你好ab');
        $screen->flush($first);

        $capturedOutput = '';
        $second = new Frame(6, 1);
        $second->putString(0, 0, '你好xy');
        $screen->flush($second);

        self::assertStringContainsString('xy', $capturedOutput, 'Only the changed tail is redrawn');
        self::assertSame(0, substr_count($capturedOutput, '你'), 'The unchanged wide character is left alone');
    }

    #[Test]
    public function fullRedrawOnFirstFlush(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);
        $frame = new Frame(5, 2);
        $frame->putString(0, 0, 'Hello');

        $screen->flush($frame);

        self::assertStringContainsString('Hello', $capturedOutput);
    }

    #[Test]
    public function failedFullRedrawIsRetriedInFull(): void
    {
        $terminal = new class implements TerminalOutput {
            public bool $failNextWrite = true;
            public string $output = '';

            public function write(string $data): void
            {
                if ($this->failNextWrite) {
                    $this->failNextWrite = false;
                    throw new RuntimeException('write failed');
                }

                $this->output .= $data;
            }

            public function flush(): void
            {
            }
        };
        $screen = new Screen($terminal);
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'Hello');

        try {
            $screen->flush($frame);
            self::fail('Expected the first write to fail');
        } catch (RuntimeException $error) {
            self::assertSame('write failed', $error->getMessage());
        }

        self::assertTrue($frame->isRowDirty(0));

        $screen->flush($frame);

        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output);
        self::assertStringContainsString('Hello', $terminal->output);
    }

    #[Test]
    public function ansiProfileDownsamplesTruecolorTo16ColorSgr(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal, ColorProfile::Ansi);

        $frame = new Frame(5, 1);
        $frame->putStyled(0, 0, 'RED', new Style(foreground: Color::rgb(255, 0, 0)));

        $screen->flush($frame);

        self::assertStringNotContainsString('38;2;255;0;0', $capturedOutput, 'A 16-color terminal must never receive truecolor SGR');
        self::assertStringContainsString('91', $capturedOutput, 'Truecolor red must degrade to bright red (SGR 91)');
    }

    #[Test]
    public function asciiProfileStripsColorSgrEntirely(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal, ColorProfile::Ascii);

        $frame = new Frame(5, 1);
        $frame->putStyled(0, 0, 'X', new Style(foreground: Color::rgb(255, 0, 0), background: Color::rgb(0, 0, 255)));

        $screen->flush($frame);

        self::assertStringNotContainsString('38;', $capturedOutput, 'NO_COLOR/dumb terminals must not receive foreground SGR');
        self::assertStringNotContainsString('48;', $capturedOutput, 'NO_COLOR/dumb terminals must not receive background SGR');
        self::assertStringContainsString('X', $capturedOutput, 'Content itself must still render');
    }

    #[Test]
    public function diffOnlyEmitsChangedCells(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $frame = new Frame(10, 2);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $capturedOutput = '';
        $frame->putChar(5, 0, 'X');
        $screen->flush($frame);

        self::assertStringContainsString('X', $capturedOutput);
        self::assertStringNotContainsString('AAAA', $capturedOutput);
    }

    #[Test]
    public function diffUsesAbsolutePositioningForFirstChangedCell(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $frame = new Frame(20, 5);
        $frame->putString(0, 0, 'Initial row zero');
        $frame->putString(0, 1, 'Row one content');
        $screen->flush($frame);

        $capturedOutput = '';
        $frame->clear();
        $frame->putString(0, 0, 'Changed row zero');
        $frame->putString(0, 1, 'Row one content');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1;1H", $capturedOutput, 'First move must use absolute positioning');
    }

    #[Test]
    public function diffCorrectlyUpdatesRowZeroAfterChangeOnLaterRow(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $frame = new Frame(20, 5);
        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame->putString(0, 0, 'AAAA', $foreground);
        $frame->putString(0, 3, 'BBBB');
        $screen->flush($frame);

        $capturedOutput = '';
        $frame->putString(0, 3, 'CCCC');
        $screen->flush($frame);

        $capturedOutput = '';
        $newForeground = Color::ansi(AnsiColor::Green)->value;
        $frame->clear();
        $frame->putString(0, 0, 'AAAA', $newForeground);
        $frame->putString(0, 3, 'CCCC');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1;1H", $capturedOutput, 'Row 0 update must use absolute positioning');
        self::assertStringContainsString('AAAA', $capturedOutput, 'Changed content on row 0 must be emitted');
    }

    #[Test]
    public function diffHandlesFullColorChangeCorrectly(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $frame = new Frame(10, 3);
        $grayColor = Color::ansi(AnsiColor::BrightBlack)->value;
        $pinkColor = Color::hex('FF06B7')->value;

        $frame->putChar(0, 0, '+', $grayColor);
        for ($column = 1; $column < 9; ++$column) {
            $frame->putChar($column, 0, '-', $grayColor);
        }
        $frame->putChar(9, 0, '+', $grayColor);
        $frame->putString(1, 1, 'Content');
        $frame->putChar(0, 2, '+', $grayColor);
        for ($column = 1; $column < 9; ++$column) {
            $frame->putChar($column, 2, '-', $grayColor);
        }
        $frame->putChar(9, 2, '+', $grayColor);
        $screen->flush($frame);

        $capturedOutput = '';
        $frame->clear();
        $frame->putChar(0, 0, '+', $pinkColor);
        for ($column = 1; $column < 9; ++$column) {
            $frame->putChar($column, 0, '-', $pinkColor);
        }
        $frame->putChar(9, 0, '+', $pinkColor);
        $frame->putString(1, 1, 'Content');
        $frame->putChar(0, 2, '+', $pinkColor);
        for ($column = 1; $column < 9; ++$column) {
            $frame->putChar($column, 2, '-', $pinkColor);
        }
        $frame->putChar(9, 2, '+', $pinkColor);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1;1H", $capturedOutput, 'Border color change must position correctly');
        self::assertStringContainsString('+', $capturedOutput, 'Border corners must be in output');
    }

    #[Test]
    public function statusBarFullRenderContainsCompleteText(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $frame = new Frame(80, 24);
        $foreground = Color::ansi(AnsiColor::White)->value;
        $background = Color::ansi(AnsiColor::Black)->value;
        $frame->fillRect(0, 23, 80, 1, $foreground, $background);
        $frame->putString(0, 23, ' focus: Settings', $foreground, $background);
        $frame->putString(35, 23, ' Tab:switch  q:quit ', $foreground, $background);
        $screen->flush($frame);

        self::assertStringContainsString('focus: Settings', $capturedOutput, 'Full render must contain status text');
        self::assertStringContainsString('Tab:switch', $capturedOutput, 'Full render must contain help text');
    }

    #[Test]
    public function statusBarDiffAfterFocusChangeEmitsCorrectCharacters(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::White)->value;
        $background = Color::ansi(AnsiColor::Black)->value;

        $frame = new Frame(80, 24);
        $frame->fillRect(0, 23, 80, 1, $foreground, $background);
        $frame->putString(0, 23, ' focus: Settings', $foreground, $background);
        $frame->putString(35, 23, ' Tab:switch  q:quit ', $foreground, $background);
        $screen->flush($frame);

        $capturedOutput = '';
        $frame->clear();
        $frame->fillRect(0, 23, 80, 1, $foreground, $background);
        $frame->putString(0, 23, ' focus: Status', $foreground, $background);
        $frame->putString(35, 23, ' Tab:switch  q:quit ', $foreground, $background);
        $screen->flush($frame);

        self::assertStringContainsString('ta', $capturedOutput, 'Diff must contain "ta" from Settings->Status');
        self::assertStringContainsString('us', $capturedOutput, 'Diff must contain "us" from Settings->Status');
        self::assertStringNotContainsString('focus:', $capturedOutput, 'Diff must not re-emit unchanged " focus:"');
        self::assertStringNotContainsString('Tab:switch', $capturedOutput, 'Diff must not re-emit unchanged help text');
    }

    #[Test]
    public function statusBarDiffDoesNotEmitClearSequences(): void
    {
        $capturedOutput = '';
        $terminal = $this->createTerminalMock($capturedOutput);
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::White)->value;
        $background = Color::ansi(AnsiColor::Black)->value;

        $frame = new Frame(80, 24);
        $frame->fillRect(0, 23, 80, 1, $foreground, $background);
        $frame->putString(0, 23, ' focus: Settings', $foreground, $background);
        $screen->flush($frame);

        $capturedOutput = '';
        $frame->clear();
        $frame->fillRect(0, 23, 80, 1, $foreground, $background);
        $frame->putString(0, 23, ' focus: Status', $foreground, $background);
        $screen->flush($frame);

        self::assertStringNotContainsString("\x1b[2J", $capturedOutput, 'Diff must not clear screen');
        self::assertStringNotContainsString("\x1b[K", $capturedOutput, 'Diff must not clear line');
        self::assertStringNotContainsString("\x1b[0K", $capturedOutput, 'Diff must not clear to end of line');
    }

    #[Test]
    public function diffSkipsUnchangedRows(): void
    {
        $terminal = new class implements TerminalOutput {
            public string $output = '';

            public function write(string $data): void
            {
                $this->output .= $data;
            }

            public function flush(): void
            {
            }
        };
        $screen = new Screen($terminal);
        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'AAAAA');
        $frame->putString(0, 1, 'BBBBB');
        $frame->putString(0, 2, 'CCCCC');
        $screen->flush($frame);
        $terminal->output = '';

        $buffer2 = new Frame(5, 3);
        $buffer2->putString(0, 0, 'AAAAA');
        $buffer2->putString(0, 1, 'XXXXX');
        $buffer2->putString(0, 2, 'CCCCC');
        $buffer2->clearDirty();
        $buffer2->dirtyRows[1] = true;
        $screen->flush($buffer2);

        self::assertStringNotContainsString('AAAAA', $terminal->output);
        self::assertStringNotContainsString('CCCCC', $terminal->output);
        self::assertStringContainsString('XXXXX', $terminal->output);
    }

    #[Test]
    public function resizeTriggersFullRedraw(): void
    {
        $terminal = new class implements TerminalOutput {
            public string $output = '';

            public function write(string $data): void
            {
                $this->output .= $data;
            }

            public function flush(): void
            {
            }
        };
        $screen = new Screen($terminal);
        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'Hello');
        $screen->flush($frame);
        $terminal->output = '';

        $buffer2 = new Frame(10, 5);
        $buffer2->putString(0, 0, 'World');
        $screen->flush($buffer2);
        self::assertStringContainsString('World', $terminal->output);
    }

    #[Test]
    public function aScreenThatForgotWhatItDrewPaintsEveryCellAgain(): void
    {
        $terminal = new class implements TerminalOutput {
            public string $output = '';

            public function write(string $data): void
            {
                $this->output .= $data;
            }

            public function flush(): void
            {
            }
        };
        $screen = new Screen($terminal);
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'Hello');
        $screen->flush($frame);
        $terminal->output = '';
        $screen->forget();
        $screen->flush($frame);
        self::assertStringContainsString('Hello', $terminal->output);
    }

    #[Test]
    public function widthOnlyChangeTriggersFullRedraw(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $buffer2 = new Frame(10, 3);
        $buffer2->putString(0, 0, 'BBBBBBBBBB');
        $screen->flush($buffer2);

        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output, 'Width-only change must trigger full redraw');
        self::assertStringContainsString('BBBBBBBBBB', $terminal->output);
    }

    #[Test]
    public function flushDoesNotRunDiffAfterFullRedraw(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        $terminal->output = '';
        $buffer2 = new Frame(5, 1);
        $buffer2->putString(0, 0, 'XXXXX');
        $screen->flush($buffer2);

        $outputCount = substr_count($terminal->output, Ansi::SYNC_START);
        self::assertSame(1, $outputCount, 'Only one render pass (full redraw) should occur on size change');
    }

    #[Test]
    public function diffFirstChangeAtColumnZeroUsesAbsoluteMove(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1;1H", $terminal->output, 'First change at (0,0) must use absolute cursor move');
        self::assertStringContainsString('X', $terminal->output);
    }

    #[Test]
    public function diffEmitsMoveForFirstChangedCellEvenAtOrigin(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'B');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1;1H", $terminal->output, 'needMove=true must cause cursor positioning even at (0,0)');
    }

    #[Test]
    public function diffProcessesRowZero(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 2);
        $frame->putString(0, 0, 'AAA');
        $frame->putString(0, 1, 'BBB');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output, 'Row 0 changes must be emitted');
        self::assertStringNotContainsString('BBB', $terminal->output, 'Unchanged row 1 must not be emitted');
    }

    #[Test]
    public function diffSetsNeedMoveAfterSkippingNonDirtyRow(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'AAAAA');
        $frame->putString(0, 1, 'BBBBB');
        $frame->putString(0, 2, 'CCCCC');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(0, 2, 'Y');
        $frame->clearDirty();
        $frame->dirtyRows[0] = true;
        $frame->dirtyRows[2] = true;
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
        self::assertStringContainsString("\x1b[3;1H", $terminal->output, 'Must reposition cursor to row 2 after skipping non-dirty row 1');
    }

    #[Test]
    public function diffSetsNeedMoveAfterUnchangedCellGap(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(5, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
        self::assertStringNotContainsString('XY', $terminal->output, 'X and Y must not be adjacent in output since there is a gap');
        self::assertStringContainsString("\x1b[4C", $terminal->output, 'Must emit cursor right for Y after unchanged gap');
    }

    #[Test]
    public function diffTracksXPositionForConsecutiveChangedCells(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(6, 1);
        $frame->putString(0, 0, 'ABCDEF');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground);
        $frame->putChar(1, 0, 'Y', $foreground);
        $frame->putChar(2, 0, 'Z', $foreground);
        $screen->flush($frame);

        self::assertStringContainsString('XYZ', $terminal->output, 'Consecutive same-style changes should be a single run');
        $absoluteMovesCount = substr_count($terminal->output, "\x1b[1;1H");
        self::assertSame(1, $absoluteMovesCount, 'Should only have one cursor move for consecutive cells');
    }

    #[Test]
    public function diffEmitsCursorMoveWhenRowChangesAfterChangedRun(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 2);
        $frame->putString(0, 0, 'AAAAA');
        $frame->putString(0, 1, 'BBBBB');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(0, 1, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
        self::assertStringContainsString("\x1b[2;1H", $terminal->output, 'Must move cursor to row 1 for Y');
    }

    #[Test]
    public function diffSetsNeedMoveAtEndOfRow(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 2);
        $frame->putString(0, 0, 'AAAAA');
        $frame->putString(0, 1, 'BBBBB');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(4, 0, 'X');
        $frame->putChar(0, 1, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[2;1H", $terminal->output, 'Must reposition cursor at start of new row after end of previous row');
    }

    #[Test]
    public function aRepaintedButUnchangedFrameWritesNothing(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);
        self::assertStringContainsString('X', $terminal->output);

        $terminal->output = '';
        $frame->putString(0, 0, 'XAAAA');
        $screen->flush($frame);
        self::assertSame('', $terminal->output);
    }

    #[Test]
    public function diffClearsDirtyFlagsAfterRender(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);

        self::assertEmpty($frame->dirtyRows, 'dirtyRows must be cleared after diff render');
    }

    #[Test]
    public function fullRedrawResetsStyleState(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA', $foreground);
        $screen->flush($frame);

        $terminal->output = '';
        $buffer2 = new Frame(5, 2);
        $buffer2->putString(0, 0, 'BBBBB', $foreground);
        $screen->flush($buffer2);

        $sgrForRed = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($sgrForRed, $terminal->output, 'Must re-emit foreground after style reset in full redraw');
    }

    #[Test]
    public function fullRedrawEmitsSyncStart(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        self::assertStringContainsString(Ansi::SYNC_START, $terminal->output, 'Full redraw must begin with SYNC_START');
    }

    #[Test]
    public function fullRedrawSendsItsHeaderOnlyForRowZero(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 3);
        $frame->putString(0, 0, 'AAA');
        $frame->putString(0, 1, 'BBB');
        $frame->putString(0, 2, 'CCC');
        $screen->flush($frame);

        self::assertSame(2, substr_count($terminal->output, Ansi::HIDE_CURSOR), 'HIDE_CURSOR appears in renderFull and cursor post-pass');
        self::assertSame(1, substr_count($terminal->output, Ansi::CURSOR_HOME));
        self::assertStringContainsString("\x1b[2;1H", $terminal->output, 'Row 1 must use cursorToRowStart positioning');
        self::assertStringContainsString("\x1b[3;1H", $terminal->output, 'Row 2 must use cursorToRowStart positioning');
    }

    #[Test]
    public function fullRedrawRowZeroContainsAllHeaderSequences(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'XYZ');
        $screen->flush($frame);

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $terminal->output);
        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output);
        self::assertStringContainsString(Ansi::RESET_STYLE, $terminal->output);

        $headerStart = strpos($terminal->output, Ansi::HIDE_CURSOR);
        $headerEnd = strpos($terminal->output, 'XYZ');
        self::assertNotFalse($headerStart);
        self::assertNotFalse($headerEnd);
        self::assertLessThan($headerEnd, $headerStart, 'Header sequences must come before content');
    }

    #[Test]
    public function fullRedrawEndsWithResetStyleAndSyncEnd(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        $resetPos = strrpos($terminal->output, Ansi::RESET_STYLE);
        $syncEndPos = strrpos($terminal->output, Ansi::SYNC_END);
        self::assertNotFalse($resetPos, 'Output must contain RESET_STYLE');
        self::assertNotFalse($syncEndPos, 'Output must contain SYNC_END');
        self::assertLessThan($syncEndPos, $resetPos, 'RESET_STYLE must come before SYNC_END at end');
    }

    #[Test]
    public function fullRedrawResetsCurrentStyleAfterOutput(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Yellow)->value;
        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA', $foreground);
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'B', $foreground);
        $screen->flush($frame);

        $sgrForYellow = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($sgrForYellow, $terminal->output, 'Diff after full redraw must re-emit style because resetCurrentStyle was called');
    }

    #[Test]
    public function fullRedrawCallsTerminalFlush(): void
    {
        $flushCount = 0;
        $terminal = new class($flushCount) implements TerminalOutput {
            public string $output = '';

            public function __construct(private int &$flushCount)
            {
            }

            public function write(string $data): void
            {
                $this->output .= $data;
            }

            public function flush(): void
            {
                ++$this->flushCount;
            }
        };
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        self::assertGreaterThanOrEqual(1, $flushCount, 'Full redraw must call terminal->flush()');
    }

    #[Test]
    public function fullRedrawClearsDirtyFlags(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        self::assertEmpty($frame->dirtyRows, 'dirtyRows must be cleared after full render');
    }

    #[Test]
    public function diffCollectsConsecutiveChangedCellsIntoOneRun(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(1, 0, 'Y');
        $frame->putChar(2, 0, 'Z');
        $screen->flush($frame);

        self::assertStringContainsString('XYZ', $terminal->output, 'Changed cells with same style must be collected into one run');
    }

    #[Test]
    public function diffBreaksRunWhenCellIsUnchanged(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame->putChar(0, 0, 'X', $foreground);
        $frame->putChar(2, 0, 'Z', $foreground);
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Z', $terminal->output);
        self::assertStringNotContainsString('XBZ', $terminal->output, 'Run must break at unchanged cell B');
    }

    #[Test]
    public function diffBreaksRunWhenStyleChanges(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'X', $foreground1);
        $frame->putChar(1, 0, 'Y', $foreground2);
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
        $sgr1 = Color::fromValue($foreground1)->toForegroundSgr();
        $sgr2 = Color::fromValue($foreground2)->toForegroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output, 'First style must be emitted');
        self::assertStringContainsString($sgr2, $terminal->output, 'Second style must be emitted');
    }

    #[Test]
    public function fullRedrawCollectsConsecutiveSameStyleCells(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE', $foreground);
        $screen->flush($frame);

        self::assertStringContainsString('ABCDE', $terminal->output);
    }

    #[Test]
    public function fullRedrawBreaksRunAtStyleBoundary(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame = new Frame(6, 1);
        $frame->putString(0, 0, 'ABC', $foreground1);
        $frame->putString(3, 0, 'DEF', $foreground2);
        $screen->flush($frame);

        self::assertStringContainsString('ABC', $terminal->output);
        self::assertStringContainsString('DEF', $terminal->output);
        $sgr1 = Color::fromValue($foreground1)->toForegroundSgr();
        $sgr2 = Color::fromValue($foreground2)->toForegroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output);
        self::assertStringContainsString($sgr2, $terminal->output);
    }

    #[Test]
    public function cellHasStyleMatchesAllThreeComponents(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $background = Color::ansi(AnsiColor::Blue)->value;
        $attributes = Attr::BOLD;

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground, $background, $attributes);
        $frame->putChar(1, 0, 'Y', $foreground, $background, $attributes);
        $frame->putChar(2, 0, 'Z', $foreground, 0, $attributes);
        $screen->flush($frame);

        self::assertStringContainsString('XY', $terminal->output, 'X and Y have same style and should be in one run');
        $sgrForBackground = Color::fromValue($background)->toBackgroundSgr();
        $sgrCountBg = substr_count($terminal->output, $sgrForBackground);
        self::assertGreaterThanOrEqual(1, $sgrCountBg, 'Background must be emitted');
    }

    #[Test]
    public function cellHasStyleDetectsForegroundDifference(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'X', $foreground1);
        $frame->putChar(1, 0, 'Y', $foreground2);
        $screen->flush($frame);

        $sgr1 = Color::fromValue($foreground1)->toForegroundSgr();
        $sgr2 = Color::fromValue($foreground2)->toForegroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output, 'First foreground must be in output');
        self::assertStringContainsString($sgr2, $terminal->output, 'Different foreground must break run');
    }

    #[Test]
    public function cellHasStyleDetectsBackgroundDifference(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $background1 = Color::ansi(AnsiColor::Red)->value;
        $background2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'X', 0, $background1);
        $frame->putChar(1, 0, 'Y', 0, $background2);
        $screen->flush($frame);

        $sgr1 = Color::fromValue($background1)->toBackgroundSgr();
        $sgr2 = Color::fromValue($background2)->toBackgroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output, 'First background must be in output');
        self::assertStringContainsString($sgr2, $terminal->output, 'Different background must break run');
    }

    #[Test]
    public function cellHasStyleDetectsAttributeDifference(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', 0, 0, Attr::BOLD);
        $frame->putChar(1, 0, 'Y', 0, 0, Attr::ITALIC);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1m", $terminal->output, 'Bold SGR must be emitted');
        self::assertStringContainsString('3', $terminal->output, 'Italic SGR must be emitted');
    }

    #[Test]
    public function characterColumnsReturnsOneForEmptyString(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE', $foreground);
        $screen->flush($frame);

        $terminal->output = '';
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'X', $foreground2);
        $frame->putChar(3, 0, 'Y', $foreground2);
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
    }

    #[Test]
    public function diffOutputIsWrappedInSyncStartAndSyncEnd(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);

        self::assertStringContainsString(Ansi::SYNC_START, $terminal->output, 'Diff output must contain SYNC_START');
        self::assertStringContainsString(Ansi::SYNC_END, $terminal->output, 'Diff output must contain SYNC_END');

        $syncStart = strpos($terminal->output, Ansi::SYNC_START);
        $content = strpos($terminal->output, 'X');
        $syncEnd = strrpos($terminal->output, Ansi::SYNC_END);
        self::assertLessThan($content, $syncStart, 'SYNC_START before content');
        self::assertGreaterThan($content, $syncEnd, 'SYNC_END after content');
    }

    #[Test]
    public function diffCallsTerminalFlush(): void
    {
        $flushCount = 0;
        $terminal = new class($flushCount) implements TerminalOutput {
            public string $output = '';

            public function __construct(private int &$flushCount)
            {
            }

            public function write(string $data): void
            {
                $this->output .= $data;
            }

            public function flush(): void
            {
                ++$this->flushCount;
            }
        };
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);
        $flushCount = 0;

        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);

        self::assertGreaterThanOrEqual(1, $flushCount, 'Diff must call terminal->flush()');
    }

    #[Test]
    public function resetCurrentStyleZerosAllTrackedValues(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $background = Color::ansi(AnsiColor::Blue)->value;
        $attributes = Attr::BOLD;

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA', $foreground, $background, $attributes);
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground, $background, $attributes);
        $screen->flush($frame);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        $bgSgr = Color::fromValue($background)->toBackgroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'Must re-emit foreground after reset');
        self::assertStringContainsString($bgSgr, $terminal->output, 'Must re-emit background after reset');
        self::assertStringContainsString('1', $terminal->output, 'Must re-emit bold attribute after reset');
    }

    #[Test]
    public function snapshotFrontBufferCreatesNewBufferOnSizeChange(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $buffer2 = new Frame(5, 5);
        $buffer2->putString(0, 0, 'BBBBB');
        $screen->flush($buffer2);

        $terminal->output = '';
        $buffer3 = new Frame(5, 5);
        $buffer3->putString(0, 0, 'BBBBB');
        $buffer3->putString(0, 4, 'CCCCC');
        $screen->flush($buffer3);

        self::assertStringContainsString('CCCCC', $terminal->output, 'New rows must be rendered after height change');
        self::assertStringNotContainsString('BBBBB', $terminal->output, 'Unchanged row 0 should not be re-emitted');
    }

    #[Test]
    public function aChangeAlreadyWrittenIsNotWrittenAgain(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 2);
        $frame->putString(0, 0, 'AAAAA');
        $frame->putString(0, 1, 'BBBBB');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);
        self::assertStringContainsString('X', $terminal->output);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);
        self::assertSame('', $terminal->output, 'An already-synced change writes nothing');
    }

    #[Test]
    public function styleDeltaReturnsEmptyWhenNoChange(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA', $foreground);
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground);
        $frame->putChar(1, 0, 'Y', $foreground);
        $screen->flush($frame);

        $stripped = str_replace([Ansi::SYNC_START, Ansi::SYNC_END, Ansi::RESET_STYLE], '', $terminal->output);
        $sgrForRed = "\x1b[" . Color::fromValue($foreground)->toForegroundSgr() . 'm';
        $sgrCount = substr_count($stripped, $sgrForRed);
        self::assertSame(1, $sgrCount, 'Same style used twice should only emit SGR once');
    }

    #[Test]
    public function styleDeltaEmitsResetWhenAttributeRemoved(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', 0, 0, Attr::BOLD);
        $frame->putChar(1, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[0mY", $terminal->output, 'Removing bold must emit SGR reset (0)');
    }

    #[Test]
    public function styleDeltaReEmitsRemainingAttributesAfterReset(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAA', 0, 0, Attr::BOLD | Attr::ITALIC);
        $frame->putChar(4, 0, 'B');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->clear();
        $frame->putString(0, 0, 'XXXXX', 0, 0, Attr::ITALIC);
        $screen->flush($frame);

        self::assertStringContainsString('0', $terminal->output, 'Must reset when removing attributes');
        self::assertStringContainsString('3', $terminal->output, 'Must re-emit remaining italic after reset');
    }

    #[Test]
    public function styleDeltaReEmitsForegroundAndBackgroundAfterAttributeReset(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $background = Color::ansi(AnsiColor::Blue)->value;

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAA', $foreground, $background, Attr::BOLD);
        $frame->putChar(4, 0, 'B');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->clear();
        $frame->putString(0, 0, 'XXXXX', $foreground, $background);
        $screen->flush($frame);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        $bgSgr = Color::fromValue($background)->toBackgroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'Must re-emit foreground after attribute reset');
        self::assertStringContainsString($bgSgr, $terminal->output, 'Must re-emit background after attribute reset');
    }

    #[Test]
    public function styleDeltaEmitsOnlyAddedAttributes(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAA', 0, 0, Attr::BOLD);
        $frame->putChar(4, 0, 'B');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->clear();
        $frame->putString(0, 0, 'XXXXX', 0, 0, Attr::BOLD | Attr::ITALIC);
        $screen->flush($frame);

        self::assertStringContainsString('3', $terminal->output, 'Must emit italic SGR code when added');
    }

    #[Test]
    public function styleDeltaEmitsForegroundChangeFromColorToDefault(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(6, 1);
        $frame->putString(0, 0, 'AAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground);
        $frame->putChar(1, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('39', $terminal->output, 'Must emit SGR 39 (default foreground) when going from colored to default');
    }

    #[Test]
    public function styleDeltaEmitsForegroundChangeFromDefaultToColor(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAA');
        $frame->putChar(4, 0, 'B');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame->clear();
        $frame->putString(0, 0, 'XXXXX', $foreground);
        $screen->flush($frame);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'Must emit foreground SGR when changing from default to color');
    }

    #[Test]
    public function styleDeltaEmitsBackgroundChangeFromColorToDefault(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $background = Color::ansi(AnsiColor::Blue)->value;
        $frame = new Frame(6, 1);
        $frame->putString(0, 0, 'AAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', 0, $background);
        $frame->putChar(1, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('49', $terminal->output, 'Must emit SGR 49 (default background) when going from colored to default');
    }

    #[Test]
    public function styleDeltaEmitsBackgroundChangeFromDefaultToColor(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAA');
        $frame->putChar(4, 0, 'B');
        $screen->flush($frame);

        $terminal->output = '';
        $background = Color::ansi(AnsiColor::Blue)->value;
        $frame->clear();
        $frame->putString(0, 0, 'XXXXX', 0, $background);
        $screen->flush($frame);

        $bgSgr = Color::fromValue($background)->toBackgroundSgr();
        self::assertStringContainsString($bgSgr, $terminal->output, 'Must emit background SGR when changing from default to color');
    }

    #[Test]
    public function moveCursorSameRowDeltaXZeroReturnsEmpty(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(1, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('XY', $terminal->output, 'Adjacent cells produce no extra cursor move');
    }

    #[Test]
    public function moveCursorSameRowDeltaXOneUsesCursorRightOne(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(1, 0, 'X');
        $frame->putChar(3, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString(Ansi::CURSOR_RIGHT_ONE, $terminal->output, 'Delta X=1 must use CURSOR_RIGHT_ONE');
    }

    #[Test]
    public function moveCursorSameRowDeltaXGreaterThanOneUsesCursorRight(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(5, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[4C", $terminal->output, 'Delta X=4 must use cursorRight(4)');
    }

    #[Test]
    public function moveCursorSameColumnDeltaYOneUsesCursorDownOne(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 3);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $frame->putString(0, 1, 'BBBBBBBBBB');
        $frame->putString(0, 2, 'CCCCCCCCCC');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(4, 0, 'X');
        $frame->putChar(5, 1, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString(Ansi::CURSOR_DOWN_ONE, $terminal->output, 'Same column after end of run, delta Y=1 must use CURSOR_DOWN_ONE');
    }

    #[Test]
    public function moveCursorSameColumnDeltaYGreaterThanOneUsesCursorDown(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 4);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $frame->putString(0, 1, 'BBBBBBBBBB');
        $frame->putString(0, 2, 'CCCCCCCCCC');
        $frame->putString(0, 3, 'DDDDDDDDDD');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(4, 0, 'X');
        $frame->putChar(5, 3, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[3B", $terminal->output, 'Same column after end of run, delta Y=3 must use cursorDown(3)');
    }

    #[Test]
    public function moveCursorDifferentRowAndColumnUsesAbsolutePosition(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 5);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $frame->putString(0, 3, 'BBBBBBBBBB');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(5, 3, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[4;6H", $terminal->output, 'Different row AND column must use cursorTo(row, col)');
    }

    #[Test]
    public function attributesToSgrEmitsBoldCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::BOLD);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1m", $terminal->output, 'Bold must emit SGR code 1');
    }

    #[Test]
    public function attributesToSgrEmitsFaintCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::FAINT);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[2m", $terminal->output, 'Faint must emit SGR code 2');
    }

    #[Test]
    public function attributesToSgrEmitsItalicCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::ITALIC);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[3m", $terminal->output, 'Italic must emit SGR code 3');
    }

    #[Test]
    public function attributesToSgrEmitsUnderlineCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::UNDERLINE);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[4m", $terminal->output, 'Underline must emit SGR code 4');
    }

    #[Test]
    public function attributesToSgrEmitsBlinkCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::BLINK);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[5m", $terminal->output, 'Blink must emit SGR code 5');
    }

    #[Test]
    public function attributesToSgrEmitsReverseCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::REVERSE);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[7m", $terminal->output, 'Reverse must emit SGR code 7');
    }

    #[Test]
    public function attributesToSgrEmitsStrikethroughCode(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::STRIKETHROUGH);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[9m", $terminal->output, 'Strikethrough must emit SGR code 9');
    }

    #[Test]
    public function attributesToSgrEmitsCombinedCodes(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', 0, 0, Attr::BOLD | Attr::UNDERLINE);
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1;4m", $terminal->output, 'Bold+Underline must emit SGR code 1;4');
    }

    #[Test]
    public function heightOnlyChangeTriggersFullRedraw(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $buffer2 = new Frame(5, 5);
        $buffer2->putString(0, 0, 'BBBBB');
        $screen->flush($buffer2);

        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output, 'Height-only change must trigger full redraw');
    }

    #[Test]
    public function moveCursorSameRowNegativeDeltaXUsesCursorLeft(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 2);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $frame->putString(0, 1, 'BBBBBBBBBB');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame->putChar(8, 0, 'X', $foreground);
        $frame->putChar(2, 0, 'Y', $foreground);
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
    }

    // --- Cursor up is unreachable from renderDiff (rows scan top-down) ---
    // --- Test cursor behavior on row 0 with changes at same column on later rows ---
    #[Test]
    public function moveCursorUsesAbsoluteWhenRowAndColumnDiffer(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 4);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $frame->putString(0, 1, 'BBBBBBBBBB');
        $frame->putString(0, 2, 'CCCCCCCCCC');
        $frame->putString(0, 3, 'DDDDDDDDDD');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(3, 0, 'X');
        $frame->putChar(7, 3, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
        self::assertStringContainsString("\x1b[4;8H", $terminal->output, 'Different row and column must use absolute cursor positioning');
    }

    #[Test]
    public function diffProducesNoOutputWhenNothingChanged(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        self::assertSame('', $terminal->output, 'An unchanged buffer writes nothing');
    }

    #[Test]
    public function styleDeltaDoesNotEmitSgrWhenNothingChanges(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(1, 0, 'Y');
        $screen->flush($frame);

        $content = $terminal->output;
        $stripped = str_replace([Ansi::SYNC_START, Ansi::SYNC_END], '', $content);
        $sgrCount = preg_match_all('/\x1b\[\d+(?:;\d+)*m/', $stripped);
        self::assertSame(0, $sgrCount, 'Text in the default style needs no SGR sequence');
    }

    #[Test]
    public function aFullRedrawAndItsCaretGoOutInOneWrite(): void
    {
        $writeCount = 0;
        $terminal = new class($writeCount) implements TerminalOutput {
            public string $output = '';

            public function __construct(private int &$writeCount)
            {
            }

            public function write(string $data): void
            {
                $this->output .= $data;
                ++$this->writeCount;
            }

            public function flush(): void
            {
            }
        };
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        self::assertSame(1, $writeCount);
        self::assertStringContainsString('ABC', $terminal->output);
    }

    // --- Test: diff with multiple non-adjacent changes on same row ---
    #[Test]
    public function diffHandlesMultipleNonAdjacentChangesOnSameRow(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(9, 0, 'Z');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Z', $terminal->output);
    }

    // --- Test: emitOutput wraps with SYNC_START + content + SYNC_END ---
    #[Test]
    public function aFrameThatEndsStyledResetsTheStyleInsideTheSync(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'Z', 0, 0, Attr::BOLD);
        $screen->flush($frame);

        $syncStartPos = strpos($terminal->output, Ansi::SYNC_START);
        $resetPos = strrpos($terminal->output, Ansi::RESET_STYLE);
        $syncEndPos = strrpos($terminal->output, Ansi::SYNC_END);

        self::assertNotFalse($syncStartPos);
        self::assertNotFalse($resetPos);
        self::assertNotFalse($syncEndPos);
        self::assertLessThan($resetPos, $syncStartPos);
        self::assertLessThan($syncEndPos, $resetPos);
    }

    // --- Test: styleDelta with removedAttributes re-emits full style ---
    #[Test]
    public function styleDeltaWithRemovedAttributesEmitsResetThenFullStyle(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $background = Color::ansi(AnsiColor::Blue)->value;

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AA', $foreground, $background, Attr::BOLD | Attr::ITALIC);
        $frame->putString(2, 0, 'BBB', $foreground, $background, Attr::ITALIC);
        $screen->flush($frame);

        $output = $terminal->output;
        self::assertStringContainsString('0', $output, 'Removed BOLD must trigger reset');
        self::assertStringContainsString('3', $output, 'Remaining ITALIC must be re-emitted');
    }

    // --- Test: styleDelta addedAttributes branch ---
    #[Test]
    public function styleDeltaAddsNewAttributesWithoutReset(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB', 0, 0, Attr::BOLD | Attr::UNDERLINE);
        $screen->flush($frame);

        $output = $terminal->output;
        $resetCount = substr_count($output, "\x1b[0;");
        $boldPos = strpos($output, "\x1b[1m");
        self::assertNotFalse($boldPos);
        self::assertStringContainsString('4', $output, 'Must emit underline code when adding to existing bold');
    }

    // --- Test: foreground ternary in no-removed-attributes branch ---
    #[Test]
    public function styleDeltaForegroundTernaryEmitsCorrectSgr(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Yellow)->value;

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', $foreground1);
        $frame->putString(2, 0, 'BB', $foreground2);
        $screen->flush($frame);

        $sgr1 = Color::fromValue($foreground1)->toForegroundSgr();
        $sgr2 = Color::fromValue($foreground2)->toForegroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output, 'First foreground must be emitted');
        self::assertStringContainsString($sgr2, $terminal->output, 'Changed foreground must be emitted');
    }

    // --- Test: background ternary in no-removed-attributes branch ---
    #[Test]
    public function styleDeltaBackgroundTernaryEmitsCorrectSgr(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $background1 = Color::ansi(AnsiColor::Red)->value;
        $background2 = Color::ansi(AnsiColor::Yellow)->value;

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, $background1);
        $frame->putString(2, 0, 'BB', 0, $background2);
        $screen->flush($frame);

        $sgr1 = Color::fromValue($background1)->toBackgroundSgr();
        $sgr2 = Color::fromValue($background2)->toBackgroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output, 'First background must be emitted');
        self::assertStringContainsString($sgr2, $terminal->output, 'Changed background must be emitted');
    }

    // --- Test: foreground from color to 0 in removed-attributes branch ---
    #[Test]
    public function styleDeltaDoesNotEmitForegroundWhenZeroInRemovedAttributesBranch(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB');
        $screen->flush($frame);

        $output = $terminal->output;
        self::assertStringContainsString('0', $output, 'Reset must be emitted for removed attributes');
    }

    // --- Test: background from color to 0 in removed-attributes branch ---
    #[Test]
    public function styleDeltaDoesNotEmitBackgroundWhenZeroInRemovedAttributesBranch(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $background = Color::ansi(AnsiColor::Blue)->value;
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, $background, Attr::BOLD);
        $frame->putString(2, 0, 'BB', 0, $background);
        $screen->flush($frame);

        $bgSgr = Color::fromValue($background)->toBackgroundSgr();
        self::assertStringContainsString($bgSgr, $terminal->output, 'Background must be re-emitted in removed-attributes branch');
    }

    // --- Test: multiple diffs verify front buffer is properly maintained ---
    #[Test]
    public function multipleDiffsAccumulateCorrectly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $screen->flush($frame);
        self::assertStringContainsString('X', $terminal->output);

        $terminal->output = '';
        $frame->putChar(1, 0, 'Y');
        $screen->flush($frame);
        self::assertStringContainsString('Y', $terminal->output);
        self::assertStringNotContainsString('X', $terminal->output, 'X was already synced and should not appear again');

        $terminal->output = '';
        $frame->putChar(2, 0, 'Z');
        $screen->flush($frame);
        self::assertStringContainsString('Z', $terminal->output);
        self::assertStringNotContainsString('Y', $terminal->output, 'Y was already synced');
    }

    // --- Test: full redraw with styled content includes SGR ---
    #[Test]
    public function fullRedrawIncludesSgrForStyledContent(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Green)->value;
        $background = Color::ansi(AnsiColor::Blue)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'HELLO', $foreground, $background, Attr::BOLD);
        $screen->flush($frame);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        $bgSgr = Color::fromValue($background)->toBackgroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'Full render must include foreground SGR');
        self::assertStringContainsString($bgSgr, $terminal->output, 'Full render must include background SGR');
        self::assertStringContainsString('1', $terminal->output, 'Full render must include bold SGR');
        self::assertStringContainsString('HELLO', $terminal->output);
    }

    // --- Test: cellIsUnchanged checks all four arrays ---
    #[Test]
    public function diffDetectsChangeInForegroundOnly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame->foregrounds[0] = $foreground;
        $frame->dirtyRows[0] = true;
        $screen->flush($frame);

        self::assertStringContainsString('A', $terminal->output, 'Cell with only foreground changed must be re-emitted');
    }

    #[Test]
    public function diffDetectsChangeInBackgroundOnly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $background = Color::ansi(AnsiColor::Blue)->value;
        $frame->backgrounds[0] = $background;
        $frame->dirtyRows[0] = true;
        $screen->flush($frame);

        self::assertStringContainsString('A', $terminal->output, 'Cell with only background changed must be re-emitted');
    }

    #[Test]
    public function diffDetectsChangeInAttributesOnly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->attributes[0] = Attr::BOLD;
        $frame->dirtyRows[0] = true;
        $screen->flush($frame);

        self::assertStringContainsString('A', $terminal->output, 'Cell with only attributes changed must be re-emitted');
    }

    #[Test]
    public function aStyleChangeBreaksTheEmittedRun(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(6, 1);
        $frame->putString(0, 0, 'ABCDEF');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'X', $foreground1);
        $frame->putChar(1, 0, 'Y', $foreground1);
        $frame->putChar(2, 0, 'Z', $foreground2);
        $screen->flush($frame);

        self::assertStringContainsString('XY', $terminal->output, 'Same-style adjacent cells must be grouped');
        self::assertStringContainsString('Z', $terminal->output);
        $sgr1 = Color::fromValue($foreground1)->toForegroundSgr();
        $sgr2 = Color::fromValue($foreground2)->toForegroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output);
        self::assertStringContainsString($sgr2, $terminal->output);
    }

    // --- Test: snapshotFrontBuffer on first flush ---
    #[Test]
    public function firstFlushSnapshotsFrontBuffer(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'HELLO');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putString(0, 0, 'HELLO');
        $screen->flush($frame);

        self::assertSame('', $terminal->output, 'No changes means no output');
    }

    // --- Test: cursor relative moves are correct ---
    #[Test]
    public function diffOutputUsesCorrectRelativeMovesAcrossRows(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 5);
        for ($row = 0; $row < 5; ++$row) {
            $frame->putString(0, $row, str_repeat('A', 10));
        }
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(2, 1, 'X');
        $frame->putChar(5, 2, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
        self::assertStringContainsString("\x1b[3;6H", $terminal->output, 'Different row and column must use absolute cursor positioning');
    }

    #[Test]
    public function flushAfterResizeDoesNotRunBothRenderFullAndRenderDiff(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $buffer2 = new Frame(10, 1);
        $buffer2->putString(0, 0, 'BBBBBBBBBB');
        $screen->flush($buffer2);

        $syncCount = substr_count($terminal->output, Ansi::SYNC_START);
        self::assertSame(1, $syncCount, 'Size change must trigger only one render pass (full), not both full + diff');

        self::assertSame(1, substr_count($terminal->output, Ansi::CURSOR_HOME), 'Only one full redraw');
    }

    #[Test]
    public function aForgottenScreenWritesItsStylesAgain(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA', $foreground);
        $screen->flush($frame);

        $screen->forget();

        $terminal->output = '';
        $buffer2 = new Frame(5, 1);
        $buffer2->putString(0, 0, 'BBBBB', $foreground);
        $screen->flush($buffer2);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output);
    }

    #[Test]
    public function renderFullResetsStyleBeforeEmittingContent(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'AAA', $foreground);
        $screen->flush($frame);

        $terminal->output = '';
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'B', $foreground2);
        $screen->flush($frame);

        $terminal->output = '';
        $buffer2 = new Frame(3, 2);
        $buffer2->putString(0, 0, 'CCC', $foreground);
        $screen->flush($buffer2);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'renderFull must resetCurrentStyle so foreground is re-emitted');
    }

    #[Test]
    public function fullRedrawRowZeroHeaderSequencesInCorrectOrder(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        $hidePos = strpos($terminal->output, Ansi::HIDE_CURSOR);
        $homePos = strpos($terminal->output, Ansi::CURSOR_HOME);
        $contentPos = strpos($terminal->output, 'ABC');

        self::assertNotFalse($hidePos);
        self::assertNotFalse($homePos);
        self::assertNotFalse($contentPos);

        self::assertLessThan($homePos, $hidePos, 'HIDE_CURSOR must come before CURSOR_HOME');
        self::assertLessThan($contentPos, $homePos, 'CURSOR_HOME must come before content');
    }

    #[Test]
    public function fullRedrawRowZeroHeaderIncludesResetStyle(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', $foreground);
        $screen->flush($frame);

        $syncStartPos = strpos($terminal->output, Ansi::SYNC_START);
        $firstResetPos = strpos($terminal->output, Ansi::RESET_STYLE, $syncStartPos);
        $fgSgrPos = strpos($terminal->output, Color::fromValue($foreground)->toForegroundSgr());

        self::assertNotFalse($firstResetPos, 'RESET_STYLE must be in header');
        self::assertNotFalse($fgSgrPos, 'Foreground SGR must be present');
        self::assertLessThan($fgSgrPos, $firstResetPos, 'RESET_STYLE in header must come before foreground SGR');
    }

    #[Test]
    public function fullRedrawEndsWithResetStyleBeforeSyncEnd(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(3, 1);
        $frame->putString(0, 0, 'ABC', $foreground);
        $screen->flush($frame);

        $lastResetPos = strrpos($terminal->output, Ansi::RESET_STYLE);
        $syncEndPos = strrpos($terminal->output, Ansi::SYNC_END);

        self::assertNotFalse($lastResetPos);
        self::assertNotFalse($syncEndPos);
        self::assertLessThan($syncEndPos, $lastResetPos, 'RESET_STYLE must appear before SYNC_END at end of full render');

        $contentBetween = substr($terminal->output, $lastResetPos, $syncEndPos - $lastResetPos);
        self::assertStringNotContainsString('ABC', $contentBetween, 'No content between final RESET_STYLE and SYNC_END');
    }

    #[Test]
    public function consecutiveChangesSharingOneStyleEmitAsOneRun(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame->putString(0, 0, 'XYZWV', $foreground);
        $screen->flush($frame);

        self::assertStringContainsString('XYZWV', $terminal->output, 'Consecutive same-style changed characters must be emitted as one run');

        $fgSgr = "\x1b[" . Color::fromValue($foreground)->toForegroundSgr() . 'm';
        $fgCount = substr_count($terminal->output, $fgSgr);
        self::assertSame(1, $fgCount, 'Same-style run should emit foreground SGR only once');
    }

    #[Test]
    public function collectStyledRunCollectsMultipleCharactersInFullRedraw(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'ABCDEFGHIJ', $foreground);
        $screen->flush($frame);

        self::assertStringContainsString('ABCDEFGHIJ', $terminal->output, 'collectStyledRun while loop must collect all characters into one run');

        $fgSgr = "\x1b[" . Color::fromValue($foreground)->toForegroundSgr() . 'm';
        $fgCount = substr_count($terminal->output, $fgSgr);
        self::assertSame(1, $fgCount, 'Single-style content should emit foreground SGR only once');
    }

    #[Test]
    public function narrowCharactersEachAdvanceOneColumnInADiff(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'ABCDE');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(1, 0, 'Y');
        $frame->putChar(2, 0, 'Z');
        $screen->flush($frame);

        self::assertStringContainsString('XYZ', $terminal->output);
        self::assertStringNotContainsString('D', $terminal->output, 'Must not include cell D which was not changed');
    }

    #[Test]
    public function collectStyledRunAdvancesCorrectlyForNarrowCharacters(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AB', $foreground1);
        $frame->putString(2, 0, 'CDE', $foreground2);
        $screen->flush($frame);

        self::assertStringContainsString('AB', $terminal->output);
        self::assertStringContainsString('CDE', $terminal->output);
    }

    #[Test]
    public function cellHasStyleReturnsFalseWhenNothingMatches(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $background1 = Color::ansi(AnsiColor::Yellow)->value;
        $background2 = Color::ansi(AnsiColor::Blue)->value;

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground1, $background1, Attr::BOLD);
        $frame->putChar(1, 0, 'Y', $foreground2, $background2, Attr::ITALIC);
        $screen->flush($frame);

        $sgr1 = Color::fromValue($foreground1)->toForegroundSgr();
        $sgr2 = Color::fromValue($foreground2)->toForegroundSgr();
        self::assertStringContainsString($sgr1, $terminal->output, 'First cell foreground must be emitted');
        self::assertStringContainsString($sgr2, $terminal->output, 'Second cell foreground must be emitted (style mismatch breaks run)');
    }

    #[Test]
    public function characterColumnsHandlesWideCharacterInDiff(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putString(4, 0, 'XX');
        $screen->flush($frame);

        self::assertStringContainsString('XX', $terminal->output);
    }

    #[Test]
    public function emitOutputResetsStyleForNextDiff(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X', $foreground);
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(1, 0, 'Y', $foreground);
        $screen->flush($frame);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'emitOutput must resetCurrentStyle so next diff re-emits foreground');
    }

    #[Test]
    public function snapshotFrontBufferHandlesWidthChangeCorrectly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $buffer1 = new Frame(5, 3);
        $buffer1->putString(0, 0, 'AAAAA');
        $screen->flush($buffer1);

        $buffer2 = new Frame(10, 3);
        $buffer2->putString(0, 0, 'BBBBBBBBBB');
        $screen->flush($buffer2);

        $terminal->output = '';
        $buffer3 = new Frame(10, 3);
        $buffer3->putString(0, 0, 'BBBBBBBBBB');
        $buffer3->putChar(9, 0, 'X');
        $screen->flush($buffer3);

        self::assertStringContainsString('X', $terminal->output, 'After width change, front buffer must be resized');
        self::assertStringNotContainsString('BBBBB', $terminal->output, 'Unchanged cells should not be re-emitted');
    }

    #[Test]
    public function styleDeltaEarlyReturnPreventsSpuriousSgrOutput(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(5, 1);
        $frame->putString(0, 0, 'AAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(1, 0, 'Y');
        $frame->putChar(2, 0, 'Z');
        $screen->flush($frame);

        $stripped = $terminal->output;
        $stripped = str_replace([Ansi::SYNC_START, Ansi::SYNC_END, Ansi::RESET_STYLE], '', $stripped);

        $sgrMatches = [];
        preg_match_all('/\x1b\[\d+(?:;\d+)*m/', $stripped, $sgrMatches);
        self::assertCount(0, $sgrMatches[0], 'Default-to-default style changes must not emit any SGR (via early return)');
    }

    #[Test]
    public function styleDeltaBitwiseNotDetectsRemovedAttributes(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, 0, Attr::BOLD | Attr::ITALIC);
        $frame->putString(2, 0, 'BB', 0, 0, Attr::ITALIC);
        $screen->flush($frame);

        self::assertStringContainsString('0', $terminal->output, 'Removing BOLD must trigger reset via removedAttributes check');
    }

    #[Test]
    public function styleDeltaBitwiseNotDoesNotResetWhenOnlyAddingAttributes(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB', 0, 0, Attr::BOLD | Attr::ITALIC);
        $screen->flush($frame);

        $boldSgr = "\x1b[1m";
        $boldPos = strpos($terminal->output, $boldSgr);
        self::assertNotFalse($boldPos, 'Bold must be emitted');

        $afterBold = substr($terminal->output, $boldPos + strlen($boldSgr));
        $italicTransition = $afterBold;

        self::assertStringNotContainsString("\x1b[0;", $italicTransition, 'Adding ITALIC to BOLD must NOT trigger reset (no attributes removed)');
        self::assertStringContainsString("\x1b[3m", $italicTransition, 'Must emit only the added ITALIC attribute');
    }

    #[Test]
    public function styleDeltaInRemovedAttributesBranchEmitsForegroundCorrectly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', $foreground, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB', $foreground);
        $screen->flush($frame);

        $fgSgr = Color::fromValue($foreground)->toForegroundSgr();
        self::assertStringContainsString($fgSgr, $terminal->output, 'In removed-attributes branch, foreground must be re-emitted');
    }

    #[Test]
    public function styleDeltaInRemovedAttributesBranchEmitsBackgroundCorrectly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $background = Color::ansi(AnsiColor::Blue)->value;
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, $background, Attr::BOLD);
        $frame->putString(2, 0, 'BB', 0, $background);
        $screen->flush($frame);

        $bgSgr = Color::fromValue($background)->toBackgroundSgr();
        self::assertStringContainsString($bgSgr, $terminal->output, 'In removed-attributes branch, background must be re-emitted');
    }

    #[Test]
    public function styleDeltaInRemovedAttributesBranchDoesNotEmitForegroundWhenZero(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB');
        $screen->flush($frame);

        $output = $terminal->output;
        self::assertStringContainsString("\x1b[0m", $output, 'Must reset when removing bold');
        self::assertStringNotContainsString('39', $output, 'Should not emit default foreground code 39 when foreground is 0');
    }

    #[Test]
    public function styleDeltaInRemovedAttributesBranchDoesNotEmitBackgroundWhenZero(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB');
        $screen->flush($frame);

        $output = $terminal->output;
        self::assertStringNotContainsString('49', $output, 'Should not emit default background code 49 when background is 0');
    }

    #[Test]
    public function styleDeltaForegroundSgrUsesFalseForNonBackground(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $foreground = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', $foreground, 0, Attr::BOLD);
        $frame->putString(2, 0, 'BB', $foreground);
        $screen->flush($frame);

        $fgSgrAsForeground = Color::fromValue($foreground)->toForegroundSgr();
        $fgSgrAsBackground = Color::fromValue($foreground)->toBackgroundSgr();
        self::assertStringContainsString($fgSgrAsForeground, $terminal->output, 'Foreground must use toForegroundSgr()');
        if ($fgSgrAsForeground !== $fgSgrAsBackground) {
            self::assertStringNotContainsString($fgSgrAsBackground, $terminal->output, 'Foreground must NOT use background SGR codes');
        }
    }

    #[Test]
    public function styleDeltaBackgroundSgrUsesTrueForBackground(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $background = Color::ansi(AnsiColor::Red)->value;
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'AA', 0, $background, Attr::BOLD);
        $frame->putString(2, 0, 'BB', 0, $background);
        $screen->flush($frame);

        $bgSgrAsBackground = Color::fromValue($background)->toBackgroundSgr();
        $bgSgrAsForeground = Color::fromValue($background)->toForegroundSgr();
        self::assertStringContainsString($bgSgrAsBackground, $terminal->output, 'Background must use toBackgroundSgr()');
        if ($bgSgrAsForeground !== $bgSgrAsBackground) {
            self::assertStringNotContainsString($bgSgrAsForeground, $terminal->output, 'Background must NOT use foreground SGR codes');
        }
    }

    #[Test]
    public function diffHandlesWideCharacterContinuationCellCorrectly(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(6, 1);
        $frame->putChar(0, 0, "\xe4\xb8\xad"); // U+4E2D, width 2
        $frame->putString(2, 0, 'ABCD');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(2, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output, 'Cell at column 2 must be emitted even after continuation cell at column 1');
    }

    #[Test]
    public function fullRedrawRendersWideCharacterFollowedByNarrow(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(6, 1);
        $frame->putChar(0, 0, "\xe4\xb8\xad"); // U+4E2D, width 2
        $frame->putString(2, 0, 'ABCD');
        $screen->flush($frame);

        self::assertStringContainsString("\xe4\xb8\xad", $terminal->output, 'Wide character must be in output');
        self::assertStringContainsString('ABCD', $terminal->output, 'Narrow characters after wide must be in output');
    }

    #[Test]
    public function moveCursorDeltaXZeroOnSameRowProducesNoOutput(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $foreground1 = Color::ansi(AnsiColor::Red)->value;
        $foreground2 = Color::ansi(AnsiColor::Green)->value;
        $frame->putChar(0, 0, 'X', $foreground1);
        $frame->putChar(1, 0, 'Y', $foreground2);
        $screen->flush($frame);

        self::assertStringContainsString('X', $terminal->output);
        self::assertStringContainsString('Y', $terminal->output);
    }

    #[Test]
    public function moveCursorDeltaYSubtractionIsCorrect(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 5);
        for ($row = 0; $row < 5; ++$row) {
            $frame->putString(0, $row, 'AAAAAAAAAA');
        }
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(4, 1, 'X');
        $frame->putChar(5, 3, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[2B", $terminal->output, 'deltaY=3-1=2, must use cursorDown(2)');
    }

    #[Test]
    public function moveCursorGreaterThanZeroBranchForDeltaX(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'AAAAAAAAAA');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(0, 0, 'X');
        $frame->putChar(3, 0, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[2C", $terminal->output, 'deltaX=2 (3-1) must use cursorRight(2)');
    }

    #[Test]
    public function moveCursorGreaterThanZeroBranchForDeltaY(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 5);
        for ($row = 0; $row < 5; ++$row) {
            $frame->putString(0, $row, 'AAAAAAAAAA');
        }
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putChar(4, 0, 'X');
        $frame->putChar(5, 2, 'Y');
        $screen->flush($frame);

        self::assertStringContainsString("\x1b[2B", $terminal->output, 'deltaY=2, same column, must use cursorDown(2)');
    }

    #[Test]
    public function emitCursorWritesPositionEscapeWhenBufferHasCursorPosition(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 5);
        $frame->setCursorPosition(3, 2, CursorShape::BlinkingBar);

        $screen->flush($frame);

        // cursor position uses 1-indexed row;col → row=2,col=3 maps to "\x1b[3;4H"
        self::assertStringContainsString("\x1b[3;4H", $terminal->output, 'emitCursor must write the cursorTo escape so the cursor lands at the requested position');
    }

    #[Test]
    public function emitCursorShowsCursorOnlyAfterPositioning(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(10, 5);
        $frame->setCursorPosition(3, 2, CursorShape::BlinkingBar);

        $screen->flush($frame);

        $cursorToPosition = strrpos($terminal->output, "\x1b[3;4H");
        $showCursorPosition = strrpos($terminal->output, Ansi::SHOW_CURSOR);
        self::assertNotFalse($cursorToPosition);
        self::assertNotFalse($showCursorPosition);
        self::assertGreaterThan($cursorToPosition, $showCursorPosition, 'SHOW_CURSOR must come after cursorTo to avoid flashing the cursor at the previous location');
    }

    #[Test]
    public function aRedrawAfterTheScreenWasGivenUpLeavesScrollbackAlone(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);
        $frame = new Frame(3, 2);
        $frame->putString(0, 0, 'ABC');
        $screen->flush($frame);

        $screen->forget();
        $screen->flush($frame);

        self::assertStringNotContainsString("\x1b[2J", $terminal->output);
    }

    #[Test]
    public function theCaretIsPlacedBeforeTheSynchronizedFrameEnds(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);
        $frame = new Frame(10, 5);
        $frame->putString(0, 0, 'old');
        $frame->setCursorPosition(3, 2, CursorShape::BlinkingBar);
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putString(0, 0, 'new');
        $frame->setCursorPosition(3, 2, CursorShape::BlinkingBar);
        $screen->flush($frame);

        self::assertStringEndsWith("\x1b[3;4H" . Ansi::SYNC_END, $terminal->output);
    }

    #[Test]
    public function aDiffEmitsAWideCharacterInsideARunExactlyOnce(): void
    {
        $terminal = $this->createTerminal();
        $screen = new Screen($terminal);

        $frame = new Frame(8, 1);
        $frame->putString(0, 0, '........');
        $screen->flush($frame);

        $terminal->output = '';
        $frame->putString(0, 0, 'ab你好cd');
        $screen->flush($frame);

        self::assertStringContainsString('ab你好cd', $terminal->output, 'The changed row is one contiguous run');
        self::assertSame(1, substr_count($terminal->output, '你'));
        self::assertSame(1, substr_count($terminal->output, '好'));
    }

    #[Test]
    public function aChangedRunInTheDefaultStyleIsWrittenOnceWithNoStyle(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $screen->flush(new Frame(6, 1));
        $capturedOutput = '';
        $frame = new Frame(6, 1);
        $frame->putString(2, 0, 'ab');

        $screen->flush($frame);

        self::assertSame(
            Ansi::SYNC_START . Ansi::cursorTo(0, 2) . 'ab' . Ansi::HIDE_CURSOR . Ansi::SYNC_END,
            $capturedOutput,
        );
    }

    #[Test]
    public function droppingEveryAttributeResetsWithABareZero(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'a', attributes: Attr::BOLD);
        $frame->putString(1, 0, 'b');

        $screen->flush($frame);

        self::assertStringContainsString("\x1b[1ma\x1b[0mb", $capturedOutput);
    }

    #[Test]
    public function aFullRedrawResetsTheStyleBeforeErasingTheFirstRow(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));

        $screen->flush(new Frame(4, 2));

        self::assertStringContainsString(Ansi::RESET_STYLE . Ansi::ERASE_TO_LINE_END, $capturedOutput);
    }

    #[Test]
    public function aFullRedrawMovesToEachRowBeforeErasingIt(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));

        $screen->flush(new Frame(4, 2));

        self::assertStringContainsString(Ansi::cursorToRowStart(1) . Ansi::ERASE_TO_LINE_END, $capturedOutput);
    }

    #[Test]
    public function aCellDifferingInEveryStyleAspectStartsARunOfItsOwn(): void
    {
        $capturedOutput = '';
        $screen = new Screen($this->createTerminalMock($capturedOutput));
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, 'a', Color::ansi(AnsiColor::Red)->value);
        $frame->putString(1, 0, 'b', Color::ansi(AnsiColor::Blue)->value, Color::ansi(AnsiColor::Yellow)->value, Attr::BOLD);

        $screen->flush($frame);

        self::assertStringContainsString("a\x1b[1;34;43mb", $capturedOutput);
    }

    private function createTerminalMock(string &$capturedOutput): object
    {
        return new class($capturedOutput) implements TerminalOutput {
            public function __construct(private string &$capturedOutput)
            {
            }

            public function write(string $data): void
            {
                $this->capturedOutput .= $data;
            }

            public function flush(): void
            {
            }
        };
    }

    private function createTerminal(): object
    {
        return new class implements TerminalOutput {
            public string $output = '';

            public function write(string $data): void
            {
                $this->output .= $data;
            }

            public function flush(): void
            {
            }
        };
    }
}
