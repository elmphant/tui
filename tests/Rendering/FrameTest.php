<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\SgrParser;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function ordinaryTextNeverStoresTerminalControlSequencesInCells(): void
    {
        $frame = new Frame(20, 1);

        $frame->putString(0, 0, "A\x1b[31mB\x1b[0mC\x9bD\u{009B}E");

        self::assertSame('ABCDE', implode('', array_slice($frame->characters, 0, 5)));
    }

    #[Test]
    public function constructorInitializesWithSpaces(): void
    {
        $frame = new Frame(10, 5);
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(0, $frame->foregrounds[0]);
        self::assertCount(50, $frame->characters);
    }

    #[Test]
    public function putChar(): void
    {
        $frame = new Frame(10, 5);
        $foreground = Color::ansi(1)->value;
        $frame->putChar(3, 2, 'X', $foreground);
        $index = 2 * 10 + 3;
        self::assertSame('X', $frame->characters[$index]);
        self::assertSame($foreground, $frame->foregrounds[$index]);
        self::assertTrue(isset($frame->dirtyRows[2]));
    }

    #[Test]
    public function putCharOutOfBoundsIsNoop(): void
    {
        $frame = new Frame(10, 5);
        $frame->putChar(-1, 0, 'X');
        $frame->putChar(10, 0, 'X');
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function constructorPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        self::assertWithinBudget(
            600.0,
            self::medianMicroseconds(static fn (): Frame => new Frame(200, 50)),
            'Frame::__construct (200x50)',
        );
    }

    #[Test]
    public function clearPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(200, 50);
        $row = str_repeat('x', 200);
        $dirtyEveryRow = static function () use ($frame, $row): void {
            for ($y = 0; $y < 50; ++$y) {
                $frame->putString(0, $y, $row);
            }
        };

        self::assertWithinBudget(
            150.0,
            self::medianMicrosecondsAfterSetup($dirtyEveryRow, static fn (): mixed => $frame->clear()),
            'Frame::clear (200x50, every row dirty)',
        );
    }

    #[Test]
    public function putStringAsciiPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(80, 24);
        $text = str_repeat('x', 80);

        self::assertWithinBudget(
            30.0,
            self::medianMicroseconds(static fn (): int => $frame->putString(0, 0, $text)),
            'Frame::putString (80-char ASCII)',
        );
    }

    #[Test]
    public function aLargerFrameDoesNotCostMoreToWriteTheSameString(): void
    {
        self::skipUnderXdebug();

        $text = str_repeat('x', 80);
        $small = new Frame(80, 24);
        $large = new Frame(400, 200);

        $onSmall = self::medianMicroseconds(static fn (): int => $small->putString(0, 0, $text));
        $onLarge = self::medianMicroseconds(static fn (): int => $large->putString(0, 0, $text));
        $ratio = $onLarge / $onSmall;

        self::assertLessThan(
            self::MAX_RATIO,
            $ratio,
            sprintf(
                'A 40x larger frame made putString %.1fx slower (%.1fµs vs %.1fµs). '
                . 'The cost must track the string, never the frame.',
                $ratio,
                $onLarge,
                $onSmall,
            ),
        );
    }

    #[Test]
    public function putString(): void
    {
        $frame = new Frame(20, 5);
        $frame->putString(2, 1, 'Hello');
        $index = 1 * 20 + 2;
        self::assertSame('H', $frame->characters[$index]);
        self::assertSame('o', $frame->characters[$index + 4]);
    }

    #[Test]
    public function putStringReturnsColumnsConsumed(): void
    {
        $frame = new Frame(20, 5);
        $consumed = $frame->putString(2, 1, 'Hello');
        self::assertSame(5, $consumed);
    }

    #[Test]
    public function putStringOutOfBoundsYReturnsZero(): void
    {
        $frame = new Frame(10, 5);
        $consumed = $frame->putString(0, -1, 'Hello');
        self::assertSame(0, $consumed);
    }

    #[Test]
    public function fillRect(): void
    {
        $frame = new Frame(10, 5);
        $foreground = Color::ansi(2)->value;
        $frame->fillRect(1, 1, 3, 2, $foreground);
        self::assertSame($foreground, $frame->foregrounds[1 * 10 + 1]);
        self::assertSame($foreground, $frame->foregrounds[1 * 10 + 3]);
        self::assertSame($foreground, $frame->foregrounds[2 * 10 + 1]);
        self::assertSame(0, $frame->foregrounds[0 * 10 + 1]);
    }

    #[Test]
    public function fillRectSetsCharacter(): void
    {
        $frame = new Frame(10, 5);
        $frame->fillRect(0, 0, 3, 2, character: '#');
        self::assertSame('#', $frame->characters[0]);
        self::assertSame('#', $frame->characters[1]);
        self::assertSame('#', $frame->characters[2]);
        self::assertSame('#', $frame->characters[10]);
        self::assertSame(' ', $frame->characters[3]);
    }

    #[Test]
    public function clear(): void
    {
        $frame = new Frame(10, 5);
        $frame->putChar(5, 2, 'X', Color::ansi(1)->value);
        $frame->clear();
        self::assertSame(' ', $frame->characters[2 * 10 + 5]);
        self::assertSame(0, $frame->foregrounds[2 * 10 + 5]);
    }

    #[Test]
    public function area(): void
    {
        $frame = new Frame(80, 24);
        $area = $frame->area();
        self::assertSame(0, $area->x);
        self::assertSame(0, $area->y);
        self::assertSame(80, $area->width);
        self::assertSame(24, $area->height);
    }

    #[Test]
    public function dirtyRowTracking(): void
    {
        $frame = new Frame(10, 5);
        self::assertFalse($frame->isRowDirty(0));
        $frame->putChar(0, 0, 'X');
        self::assertTrue($frame->isRowDirty(0));
        $frame->clearDirty();
        self::assertFalse($frame->isRowDirty(0));
    }

    #[Test]
    public function markAllDirty(): void
    {
        $frame = new Frame(10, 5);
        $frame->markAllDirty();
        for ($row = 0; $row < 5; ++$row) {
            self::assertTrue($frame->isRowDirty($row));
        }
    }

    #[Test]
    public function overlay(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 2);
        $source->putChar(0, 0, 'A');
        $source->putChar(1, 0, 'B');
        $source->putChar(0, 1, 'C');

        $destination->overlay($source, 2, 1);

        self::assertSame('A', $destination->characters[1 * 10 + 2]);
        self::assertSame('B', $destination->characters[1 * 10 + 3]);
        self::assertSame('C', $destination->characters[2 * 10 + 2]);
        self::assertSame(' ', $destination->characters[0]);
    }

    #[Test]
    public function overlaySkipsBlankCells(): void
    {
        $destination = new Frame(10, 5);
        $destination->putChar(2, 1, 'X');

        $source = new Frame(3, 2);
        $source->putChar(1, 0, 'Y');

        $destination->overlay($source, 2, 1);

        self::assertSame('X', $destination->characters[1 * 10 + 2]);
        self::assertSame('Y', $destination->characters[1 * 10 + 3]);
    }

    #[Test]
    public function swapArrays(): void
    {
        $bufferA = new Frame(5, 3);
        $bufferB = new Frame(5, 3);
        $bufferA->putChar(0, 0, 'A');
        $bufferB->putChar(0, 0, 'B');

        $bufferA->swapArrays($bufferB);

        self::assertSame('B', $bufferA->characters[0]);
        self::assertSame('A', $bufferB->characters[0]);
    }

    #[Test]
    public function putAnsiPlainText(): void
    {
        $frame = new Frame(20, 5);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, 'Hello');
        self::assertSame('H', $frame->characters[0]);
        self::assertSame('o', $frame->characters[4]);
        self::assertSame(5, $finalX);
        self::assertSame(0, $finalY);
    }

    #[Test]
    public function putAnsiWithColorSequences(): void
    {
        $frame = new Frame(20, 5);
        $frame->putAnsi(0, 0, "\e[31mRed\e[0m");
        self::assertSame('R', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
        self::assertSame('d', $frame->characters[2]);
        self::assertNotSame(0, $frame->foregrounds[0]);
        self::assertSame($frame->foregrounds[0], $frame->foregrounds[1]);
    }

    #[Test]
    public function putAnsiNewlineAdvancesRow(): void
    {
        $frame = new Frame(20, 5);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "AB\nCD");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[1 * 20 + 0]);
        self::assertSame('D', $frame->characters[1 * 20 + 1]);
        self::assertSame(2, $finalX);
        self::assertSame(1, $finalY);
    }

    #[Test]
    public function putAnsiTabExpansion(): void
    {
        $frame = new Frame(20, 5);
        $frame->putAnsi(0, 0, "A\tB");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
        self::assertSame('B', $frame->characters[8]);
    }

    #[Test]
    public function clearMarksOnlyRowsThatChanged(): void
    {
        $frame = new Frame(10, 5);
        $frame->putString(0, 2, 'changed');
        $frame->clearDirty();
        $frame->clear();

        self::assertFalse($frame->isRowDirty(0));
        self::assertFalse($frame->isRowDirty(1));
        self::assertTrue($frame->isRowDirty(2), 'Row 2 should be dirty because clear erased content there');
        self::assertFalse($frame->isRowDirty(3));
        self::assertFalse($frame->isRowDirty(4));
    }

    #[Test]
    public function clearMarksRowsChangedOnlyByStyleOrWidth(): void
    {
        $frame = new Frame(5, 5);
        $frame->foregrounds[1 * 5] = Color::ansi(1)->value;
        $frame->markRowDirty(1);
        $frame->backgrounds[2 * 5] = Color::ansi(2)->value;
        $frame->markRowDirty(2);
        $frame->attributes[3 * 5] = Attr::BOLD;
        $frame->markRowDirty(3);
        $frame->widths[4 * 5] = 2;
        $frame->markRowDirty(4);
        $frame->clearDirty();

        $frame->clear();

        self::assertFalse($frame->isRowDirty(0));
        self::assertSame(true, $frame->dirtyRows[1], 'Foreground-only stale rows must be dirty');
        self::assertSame(true, $frame->dirtyRows[2], 'Background-only stale rows must be dirty');
        self::assertSame(true, $frame->dirtyRows[3], 'Attribute-only stale rows must be dirty');
        self::assertSame(true, $frame->dirtyRows[4], 'Width-only stale rows must be dirty');
    }

    #[Test]
    public function clearBlankBufferMarksNoRowsDirty(): void
    {
        $frame = new Frame(10, 5);
        $frame->clear();

        self::assertSame([], $frame->dirtyRows);
    }

    #[Test]
    public function clearWithBackgroundMarksRowsWhereBackgroundDiffersFromTarget(): void
    {
        $frame = new Frame(4, 2);
        $background = Color::ansi(4)->value;

        $frame->clear($background);
        self::assertSame([0 => true, 1 => true], $frame->dirtyRows);
        self::assertSame(true, $frame->dirtyRows[0], 'Changing the base background is a visual change');
        self::assertSame(true, $frame->dirtyRows[1], 'Changing the base background must dirty every affected row');
        self::assertSame($background, $frame->backgrounds[0]);
        self::assertSame($background, $frame->backgrounds[$frame->size - 1], 'Base background must cover the final cell');

        $frame->clearDirty();
        $frame->clear(0);

        self::assertSame(true, $frame->dirtyRows[0]);
        self::assertSame(true, $frame->dirtyRows[1]);
        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->backgrounds[$frame->size - 1]);
    }

    #[Test]
    public function clearWithSameBackgroundKeepsBlankBufferClean(): void
    {
        $frame = new Frame(4, 2);
        $background = Color::ansi(4)->value;
        $frame->clear($background);
        $frame->dirtyRows = [0 => true, 1 => true];

        $frame->clear($background);

        self::assertSame([], $frame->dirtyRows);
        self::assertSame($background, $frame->backgrounds[0]);
    }

    #[Test]
    public function characterAccessor(): void
    {
        $frame = new Frame(10, 5);
        $frame->putChar(3, 2, 'Z');
        $index = 2 * 10 + 3;
        self::assertSame('Z', $frame->character($index));
    }

    #[Test]
    public function foregroundAccessor(): void
    {
        $frame = new Frame(10, 5);
        $foreground = Color::ansi(3)->value;
        $frame->putChar(0, 0, 'A', $foreground);
        self::assertSame($foreground, $frame->foreground(0));
    }

    #[Test]
    public function backgroundAccessor(): void
    {
        $frame = new Frame(10, 5);
        $background = Color::ansi(4)->value;
        $frame->putChar(1, 0, 'B', 0, $background);
        self::assertSame($background, $frame->background(1));
    }

    #[Test]
    public function attributeAccessor(): void
    {
        $frame = new Frame(10, 5);
        $frame->putChar(2, 0, 'C', 0, 0, 1);
        self::assertSame(1, $frame->attribute(2));
    }

    #[Test]
    public function accessorsReturnDefaultsForBlankCells(): void
    {
        $frame = new Frame(10, 5);
        self::assertSame(' ', $frame->character(0));
        self::assertSame(0, $frame->foreground(0));
        self::assertSame(0, $frame->background(0));
        self::assertSame(0, $frame->attribute(0));
    }

    #[Test]
    public function copyRowFrom(): void
    {
        $source = new Frame(5, 3);
        $source->putChar(0, 1, 'X', Color::ansi(1)->value, Color::ansi(2)->value, 1);
        $source->putChar(1, 1, 'Y', Color::ansi(3)->value);

        $destination = new Frame(5, 3);
        $destination->copyRowFrom($source, 1);

        $base = 1 * 5;
        self::assertSame('X', $destination->character($base));
        self::assertSame(Color::ansi(1)->value, $destination->foreground($base));
        self::assertSame(Color::ansi(2)->value, $destination->background($base));
        self::assertSame(1, $destination->attribute($base));
        self::assertSame('Y', $destination->character($base + 1));
        self::assertSame(Color::ansi(3)->value, $destination->foreground($base + 1));

        self::assertSame(' ', $destination->character(0), 'Row 0 should be untouched');
    }

    #[Test]
    public function snapshotFrom(): void
    {
        $source = new Frame(5, 3);
        $source->putChar(0, 0, 'A', Color::ansi(1)->value, Color::ansi(2)->value, 4);
        $source->putChar(4, 2, 'B');

        $destination = new Frame(5, 3);
        $destination->snapshotFrom($source);

        self::assertSame('A', $destination->character(0));
        self::assertSame(Color::ansi(1)->value, $destination->foreground(0));
        self::assertSame(Color::ansi(2)->value, $destination->background(0));
        self::assertSame(4, $destination->attribute(0));
        self::assertSame('B', $destination->character(2 * 5 + 4));
    }

    #[Test]
    public function snapshotFromIsACopy(): void
    {
        $source = new Frame(5, 3);
        $source->putChar(0, 0, 'A');

        $destination = new Frame(5, 3);
        $destination->snapshotFrom($source);

        $source->putChar(0, 0, 'Z');
        self::assertSame('A', $destination->character(0), 'Snapshot should be independent of source');
    }

    #[Test]
    public function putStringWithNegativeX(): void
    {
        $frame = new Frame(10, 1);
        $columns = $frame->putString(-3, 0, 'Hello');
        self::assertGreaterThan(0, $columns);
    }

    #[Test]
    public function overlayWithOffset(): void
    {
        $dest = new Frame(10, 5);
        $src = new Frame(3, 2);
        $src->putString(0, 0, 'AB');
        $src->putString(0, 1, 'CD');
        $dest->overlay($src, 5, 2);
        self::assertSame('A', $dest->character(2 * 10 + 5));
        self::assertSame('B', $dest->character(2 * 10 + 6));
        self::assertSame('C', $dest->character(3 * 10 + 5));
        self::assertSame('D', $dest->character(3 * 10 + 6));
    }

    #[Test]
    public function overlayNegativeOffsetClips(): void
    {
        $dest = new Frame(5, 5);
        $src = new Frame(3, 3);
        $src->putString(0, 1, 'ABC');
        $dest->overlay($src, -1, -1);
        self::assertSame('B', $dest->character(0));
        self::assertSame('C', $dest->character(1));
    }

    #[Test]
    public function fillRectClipsToBufferBounds(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(3, 3, 10, 10, 0, 0, 0, 'X');
        self::assertSame('X', $frame->character(3 * 5 + 3));
        self::assertSame('X', $frame->character(4 * 5 + 4));
    }

    #[Test]
    public function clearRestoresAllCells(): void
    {
        $frame = new Frame(5, 3);
        $frame->putString(0, 0, 'Hello');
        $frame->putChar(0, 1, 'X', 1, 2, 1);
        $frame->clear();
        for ($i = 0; $i < $frame->size; ++$i) {
            self::assertSame(' ', $frame->character($i));
            self::assertSame(0, $frame->foreground($i));
            self::assertSame(0, $frame->background($i));
            self::assertSame(0, $frame->attribute($i));
        }
    }

    #[Test]
    public function putStyledWritesTextWithStyle(): void
    {
        $frame = new Frame(20, 1);
        $style = new Style(
            foreground: Color::hex('FF0000'),
            background: Color::hex('00FF00'),
            attributes: Attr::BOLD,
        );

        $frame->putStyled(0, 0, 'Hi', $style);

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);
        self::assertSame(Color::hex('FF0000')->value, $frame->foregrounds[0]);
        self::assertSame(Color::hex('00FF00')->value, $frame->backgrounds[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    #[Test]
    public function putStyledWithUnsetColorsUsesZero(): void
    {
        $frame = new Frame(20, 1);
        $style = new Style(attributes: Attr::ITALIC);

        $frame->putStyled(0, 0, 'X', $style);

        self::assertSame(0, $frame->foregrounds[0]);
        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(Attr::ITALIC, $frame->attributes[0]);
    }

    // ── putChar bounds checking (L45-48) ──

    #[Test]
    public function putCharIgnoresNegativeX(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(-1, 0, 'X', 1);
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function putCharIgnoresXAtWidth(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(5, 0, 'X', 1);
        self::assertSame(' ', $frame->characters[4]);
        self::assertSame(0, $frame->foregrounds[4]);
    }

    #[Test]
    public function putCharIgnoresNegativeY(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(0, -1, 'X', 1);
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function putCharIgnoresYAtHeight(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(0, 5, 'X', 1);
        for ($i = 0; $i < $frame->size; ++$i) {
            self::assertSame(' ', $frame->characters[$i]);
        }
    }

    #[Test]
    public function putCharAcceptsMaxValidCoordinates(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(4, 4, 'Z', 1, 2, 3);
        $index = 4 * 5 + 4;
        self::assertSame('Z', $frame->characters[$index]);
        self::assertSame(1, $frame->foregrounds[$index]);
        self::assertSame(2, $frame->backgrounds[$index]);
        self::assertSame(3, $frame->attributes[$index]);
        self::assertTrue($frame->isRowDirty(4));
    }

    #[Test]
    public function putCharAcceptsZeroCoordinates(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(0, 0, 'A', 1, 2, 3);
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $frame->foregrounds[0]);
        self::assertSame(2, $frame->backgrounds[0]);
        self::assertSame(3, $frame->attributes[0]);
        self::assertTrue($frame->isRowDirty(0));
    }

    #[Test]
    public function putCharDoesNotMarkDirtyRowOnOutOfBounds(): void
    {
        $frame = new Frame(5, 5);
        $frame->putChar(-1, 0, 'X');
        self::assertFalse($frame->isRowDirty(0));
    }

    // ── putChar wide character handling (L56-59) ──

    #[Test]
    public function putCharWideCharacterWritesPlaceholderAtNextColumn(): void
    {
        $frame = new Frame(10, 1);
        $foreground = Color::ansi(1)->value;
        $background = Color::ansi(2)->value;
        $frame->putChar(3, 0, "\u{4E16}", $foreground, $background, Attr::BOLD);

        self::assertSame("\u{4E16}", $frame->characters[3]);
        self::assertSame($foreground, $frame->foregrounds[3]);
        self::assertSame($background, $frame->backgrounds[3]);
        self::assertSame(Attr::BOLD, $frame->attributes[3]);

        self::assertSame('', $frame->characters[4]);
        self::assertSame($foreground, $frame->foregrounds[4]);
        self::assertSame($background, $frame->backgrounds[4]);
        self::assertSame(Attr::BOLD, $frame->attributes[4]);
    }

    #[Test]
    public function putCharWideCharacterAtLastColumnIsSkipped(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(4, 0, "\u{4E16}", 1);

        self::assertSame(' ', $frame->characters[4], 'Wide char at last column must be skipped to avoid orphan continuation');
        self::assertSame(0, $frame->foregrounds[4]);
        self::assertSame(' ', $frame->characters[3], 'Previous cell should be unchanged');
    }

    #[Test]
    public function putCharOverContinuationCellBlanksOrphanedLead(): void
    {
        $frame = new Frame(10, 1);
        $frame->putChar(2, 0, "\u{4E16}");

        $frame->putChar(3, 0, 'x');

        self::assertSame(' ', $frame->characters[2], 'Orphaned wide lead must be blanked or it renders over the new char');
        self::assertSame(1, $frame->widths[2]);
        self::assertSame('x', $frame->characters[3]);
    }

    #[Test]
    public function putCharOverLeadCellBlanksOrphanedContinuation(): void
    {
        $frame = new Frame(10, 1);
        $frame->putChar(2, 0, "\u{4E16}");

        $frame->putChar(2, 0, 'x');

        self::assertSame('x', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3], "Orphaned continuation ('') must be blanked or the row shifts left on screen");
        self::assertSame(1, $frame->widths[3]);
    }

    #[Test]
    public function putStringAsciiOverWideCharactersBlanksOrphansAtBothEdges(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{4E16}\u{4E16}\u{4E16}");

        $frame->putString(1, 0, 'abcd');

        self::assertSame(' ', $frame->characters[0], 'Lead cut at run start must be blanked');
        self::assertSame(1, $frame->widths[0]);
        self::assertSame('abcd', implode('', array_slice($frame->characters, 1, 4)));
        self::assertSame(' ', $frame->characters[5], 'Continuation cut at run end must be blanked');
        self::assertSame(1, $frame->widths[5]);
    }

    #[Test]
    public function putStringGraphemePathOverWideCharactersBlanksOrphansAtBothEdges(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{4E16}\u{4E16}\u{4E16}");

        $frame->putString(1, 0, 'ébcd');

        self::assertSame(' ', $frame->characters[0], 'Lead cut at run start must be blanked');
        self::assertSame(1, $frame->widths[0]);
        self::assertSame('ébcd', implode('', array_slice($frame->characters, 1, 4)));
        self::assertSame(' ', $frame->characters[5], 'Continuation cut at run end must be blanked');
        self::assertSame(1, $frame->widths[5]);
    }

    #[Test]
    public function putAnsiOverWideCharactersBlanksOrphansAtBothEdges(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{4E16}\u{4E16}\u{4E16}");

        $frame->putAnsi(1, 0, 'abcd');

        self::assertSame(' ', $frame->characters[0], 'Lead cut at run start must be blanked');
        self::assertSame(1, $frame->widths[0]);
        self::assertSame('abcd', implode('', array_slice($frame->characters, 1, 4)));
        self::assertSame(' ', $frame->characters[5], 'Continuation cut at run end must be blanked');
        self::assertSame(1, $frame->widths[5]);
    }

    #[Test]
    public function repairNeverCrossesRowBoundaryForPoisonedContinuationAtColumnZero(): void
    {
        $frame = new Frame(10, 2);
        $frame->putChar(9, 0, 'z');
        $frame->widths[10] = 0;

        $frame->putChar(0, 1, 'x');

        self::assertSame('z', $frame->characters[9], 'Repair at column 0 must never blank the previous row');
        self::assertSame(1, $frame->widths[9]);
    }

    #[Test]
    public function emptyWriteRunNeverTriggersRepair(): void
    {
        $frame = new Frame(10, 2);
        $frame->putChar(9, 0, 'z');
        $frame->widths[10] = 0;

        $frame->putString(10, 0, 'ab');

        self::assertSame('z', $frame->characters[9], 'A fully clipped write must not repair anything');
    }

    #[Test]
    public function singleCellOverlayOverLeadBlanksItsContinuation(): void
    {
        $frame = new Frame(10, 1);
        $frame->putChar(0, 0, "\u{4E16}");

        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'x');
        $frame->overlay($source, 0, 0);

        self::assertSame('x', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
        self::assertSame(1, $frame->widths[1]);
    }

    #[Test]
    public function putCharNarrowCharacterDoesNotWritePlaceholder(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(2, 0, 'A', 1);

        self::assertSame('A', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame(0, $frame->foregrounds[3]);
    }

    // ── putString (L71-87) ──

    #[Test]
    public function putStringIgnoresNegativeY(): void
    {
        $frame = new Frame(10, 5);
        $consumed = $frame->putString(0, -1, 'Hello');
        self::assertSame(0, $consumed);
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function putStringIgnoresYAtHeight(): void
    {
        $frame = new Frame(10, 5);
        $consumed = $frame->putString(0, 5, 'Hello');
        self::assertSame(0, $consumed);
    }

    #[Test]
    public function putStringBreaksAtWidthBoundary(): void
    {
        $frame = new Frame(5, 1);
        $consumed = $frame->putString(3, 0, 'ABCDE');
        self::assertSame('A', $frame->characters[3]);
        self::assertSame('B', $frame->characters[4]);
        self::assertSame(2, $consumed);
    }

    #[Test]
    public function putStringKeepsCombiningMarkWithBase(): void
    {
        $frame = new Frame(10, 1);
        $consumed = $frame->putString(0, 0, "A\u{0300}B");
        self::assertSame("A\u{0300}", $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame(2, $consumed);
    }

    #[Test]
    public function putStringWithNegativeXWritesAtCorrectPositions(): void
    {
        $frame = new Frame(10, 1);
        $consumed = $frame->putString(-3, 0, 'ABCDE');

        self::assertSame('D', $frame->characters[0]);
        self::assertSame('E', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
        self::assertSame(5, $consumed);
    }

    #[Test]
    public function putStringNegativeXWritesVisiblePortion(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(-2, 0, 'ABCDE');
        self::assertSame('C', $frame->characters[0]);
        self::assertSame('D', $frame->characters[1]);
        self::assertSame('E', $frame->characters[2]);
    }

    #[Test]
    public function putStringMarksDirtyRow(): void
    {
        $frame = new Frame(10, 5);
        self::assertFalse($frame->isRowDirty(2));
        $frame->putString(0, 2, 'Hi');
        self::assertTrue($frame->isRowDirty(2));
    }

    #[Test]
    public function putStringWideCharacterWritesPlaceholder(): void
    {
        $frame = new Frame(10, 1);
        $foreground = Color::ansi(1)->value;
        $frame->putString(0, 0, "\u{4E16}\u{754C}", $foreground);

        self::assertSame("\u{4E16}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame("\u{754C}", $frame->characters[2]);
        self::assertSame('', $frame->characters[3]);
        self::assertSame($foreground, $frame->foregrounds[0]);
        self::assertSame($foreground, $frame->foregrounds[1]);
    }

    #[Test]
    public function putStringWideCharacterAtLastColumnIsSkipped(): void
    {
        $frame = new Frame(5, 1);
        $frame->putString(4, 0, "\u{4E16}");

        self::assertSame(' ', $frame->characters[4], 'Wide char at last column must be skipped');
    }

    #[Test]
    public function putStringReturnsCorrectColumnsForWideCharacters(): void
    {
        $frame = new Frame(20, 1);
        $consumed = $frame->putString(0, 0, "\u{4E16}\u{754C}");
        self::assertSame(4, $consumed);
    }

    #[Test]
    public function putStringEmptyTextReturnsZeroColumns(): void
    {
        $frame = new Frame(10, 1);
        $consumed = $frame->putString(0, 0, '');
        self::assertSame(0, $consumed);
    }

    // ── putStyled (L98) ──

    #[Test]
    public function putStyledReturnsColumnsConsumed(): void
    {
        $frame = new Frame(20, 1);
        $style = new Style(foreground: Color::ansi(1));
        $consumed = $frame->putStyled(0, 0, 'Hello', $style);
        self::assertSame(5, $consumed);
    }

    #[Test]
    public function putStyledPassesAllStyleProperties(): void
    {
        $frame = new Frame(20, 1);
        $foreground = Color::ansi(1);
        $background = Color::ansi(2);
        $style = new Style(foreground: $foreground, background: $background, attributes: Attr::BOLD | Attr::ITALIC);

        $frame->putStyled(0, 0, 'AB', $style);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame($foreground->value, $frame->foregrounds[0]);
        self::assertSame($background->value, $frame->backgrounds[0]);
        self::assertSame(Attr::BOLD | Attr::ITALIC, $frame->attributes[0]);
        self::assertSame($foreground->value, $frame->foregrounds[1]);
        self::assertSame($background->value, $frame->backgrounds[1]);
    }

    // ── putAnsi (L133-225) ──

    #[Test]
    public function putAnsiUsesProvidedParser(): void
    {
        $frame = new Frame(20, 1);
        $parser = new SgrParser();
        $parser->foreground = Color::ansi(1)->value;

        $frame->putAnsi(0, 0, 'AB', $parser);

        self::assertSame(Color::ansi(1)->value, $frame->foregrounds[0]);
        self::assertSame(Color::ansi(1)->value, $frame->foregrounds[1]);
    }

    #[Test]
    public function putAnsiCreatesDefaultParserWhenNoneProvided(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, 'A');
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function putAnsiParsesEscapeSequenceAndAppliesColor(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[31mA\e[0mB");
        $redForeground = Color::ansi(1)->value;
        self::assertSame($redForeground, $frame->foregrounds[0]);
        self::assertSame(0, $frame->foregrounds[1]);
    }

    #[Test]
    public function putAnsiHandlesNonSgrEscapeSequence(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\e[2JHello");
        self::assertSame('H', $frame->characters[0]);
        self::assertSame(5, $finalX);
    }

    #[Test]
    public function putAnsiSkipsIncompleteEscapeSequenceWithoutTerminator(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\e[1A");
        self::assertSame(0, $finalX);
        self::assertSame(0, $finalY);
    }

    #[Test]
    public function putAnsiNewlineResetsColumnToZero(): void
    {
        $frame = new Frame(10, 3);
        [$finalX, $finalY] = $frame->putAnsi(5, 0, "AB\n");
        self::assertSame('A', $frame->characters[5]);
        self::assertSame('B', $frame->characters[6]);
        self::assertSame(0, $finalX);
        self::assertSame(1, $finalY);
    }

    #[Test]
    public function putAnsiCarriageReturnResetsColumnToZero(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "ABC\rDE");
        self::assertSame('D', $frame->characters[0]);
        self::assertSame('E', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame(2, $finalX);
        self::assertSame(0, $finalY);
    }

    #[Test]
    public function putAnsiTabExpandsToNextTabStop(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\tA");
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('A', $frame->characters[8]);
        self::assertSame(9, $finalX);
    }

    #[Test]
    public function putAnsiTabFromMiddleOfTabStop(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "AB\tC");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
        self::assertSame('C', $frame->characters[8]);
    }

    #[Test]
    public function putAnsiTabClipsAtBufferWidth(): void
    {
        $frame = new Frame(5, 1);
        [$finalX, $finalY] = $frame->putAnsi(3, 0, "\t");
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame(' ', $frame->characters[4]);
        self::assertSame(5, $finalX);
    }

    #[Test]
    public function putAnsiTabAppliesParserStyle(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[31m\tA");
        $redForeground = Color::ansi(1)->value;
        self::assertSame($redForeground, $frame->foregrounds[0]);
        self::assertSame($redForeground, $frame->foregrounds[7]);
        self::assertSame($redForeground, $frame->foregrounds[8]);
    }

    #[Test]
    public function putAnsiSkipsControlCharactersBelow0x20(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\x01\x02B");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame(2, $finalX);
    }

    #[Test]
    public function putAnsiMultiByteUtf8Character(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\u{00E9}");
        self::assertSame("\u{00E9}", $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiThreeByteUtf8Character(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\u{2603}");
        self::assertSame("\u{2603}", $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiFourByteUtf8WideCharacter(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\u{1F600}");
        self::assertSame("\u{1F600}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame(2, $finalX);
    }

    #[Test]
    public function putAnsiZeroWidthCharacterComposesWithoutConsumingAColumn(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\u{0300}B");
        self::assertSame("A\u{0300}", $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame(2, $finalX);
    }

    #[Test]
    public function putAnsiCharacterAtWidthBoundaryIsSkipped(): void
    {
        $frame = new Frame(3, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, 'ABCDE');
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame(3, $finalX);
    }

    #[Test]
    public function putAnsiStopsAtHeightBoundary(): void
    {
        $frame = new Frame(5, 2);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "AB\nCD\nEF");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('C', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[9], 'Third line should not be written');
        self::assertSame(2, $finalY);
    }

    #[Test]
    public function putAnsiWideCharacterWritesPlaceholderAndAdvancesTwoColumns(): void
    {
        $frame = new Frame(10, 1);
        $foreground = Color::ansi(1)->value;
        $frame->putAnsi(0, 0, "\e[31m\u{4E16}A");

        self::assertSame("\u{4E16}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame('A', $frame->characters[2]);
        self::assertSame($foreground, $frame->foregrounds[0]);
        self::assertSame($foreground, $frame->foregrounds[1]);
    }

    #[Test]
    public function putAnsiWideCharacterAtLastColumnIsSkipped(): void
    {
        $frame = new Frame(5, 1);
        $frame->putAnsi(0, 0, "ABCD\u{4E16}");
        self::assertSame('D', $frame->characters[3]);
        self::assertSame(' ', $frame->characters[4], 'Wide char at last column must be skipped to avoid orphan continuation');
    }

    #[Test]
    public function putAnsiWideCharacterSetsLeadAndContinuationWidths(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(2, 0, "\u{4E16}");

        self::assertSame(2, $frame->widths[2], 'Lead cell must carry width 2');
        self::assertSame(0, $frame->widths[3], 'Continuation cell must carry width 0');
    }

    #[Test]
    public function putAnsiNarrowCharacterResetsStaleWidth(): void
    {
        $frame = new Frame(10, 1);
        $frame->putChar(2, 0, "\u{4E16}");

        $frame->putAnsi(2, 0, 'ab');

        self::assertSame(1, $frame->widths[2]);
        self::assertSame(1, $frame->widths[3]);
    }

    #[Test]
    public function putAnsiMarksDirtyRows(): void
    {
        $frame = new Frame(10, 3);
        $frame->putAnsi(0, 1, 'A');
        self::assertTrue($frame->isRowDirty(1));
        self::assertFalse($frame->isRowDirty(0));
    }

    #[Test]
    public function putAnsiMultiLineMarksDirtyRows(): void
    {
        $frame = new Frame(10, 3);
        $frame->putAnsi(0, 0, "A\nB");
        self::assertTrue($frame->isRowDirty(0));
        self::assertTrue($frame->isRowDirty(1));
        self::assertFalse($frame->isRowDirty(2));
    }

    #[Test]
    public function putAnsiWithNegativeStartingColumn(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(-2, 0, 'ABCDE');
        self::assertSame('C', $frame->characters[0]);
        self::assertSame('D', $frame->characters[1]);
        self::assertSame('E', $frame->characters[2]);
        self::assertSame(3, $finalX);
    }

    #[Test]
    public function putAnsiWithNegativeYDoesNotWrite(): void
    {
        $frame = new Frame(10, 5);
        [$finalX, $finalY] = $frame->putAnsi(0, -1, 'AB');
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function putAnsiReturnsCorrectPositionAfterEmptyText(): void
    {
        $frame = new Frame(10, 5);
        [$finalX, $finalY] = $frame->putAnsi(3, 2, '');
        self::assertSame(3, $finalX);
        self::assertSame(2, $finalY);
    }

    // ── fillRect (L228-246) ──

    #[Test]
    public function fillRectWritesCharacterAndStyleToEntireArea(): void
    {
        $frame = new Frame(10, 5);
        $foreground = Color::ansi(1)->value;
        $background = Color::ansi(2)->value;
        $frame->fillRect(1, 1, 3, 2, $foreground, $background, Attr::BOLD, '#');

        for ($row = 1; $row <= 2; ++$row) {
            for ($column = 1; $column <= 3; ++$column) {
                $index = $row * 10 + $column;
                self::assertSame('#', $frame->characters[$index], "char at ($column, $row)");
                self::assertSame($foreground, $frame->foregrounds[$index], "fg at ($column, $row)");
                self::assertSame($background, $frame->backgrounds[$index], "bg at ($column, $row)");
                self::assertSame(Attr::BOLD, $frame->attributes[$index], "attr at ($column, $row)");
            }
        }
    }

    #[Test]
    public function fillRectOverWideCharacterResetsCellWidths(): void
    {
        $frame = new Frame(10, 1);
        $frame->putChar(2, 0, "\u{4E16}");

        $frame->fillRect(2, 0, 2, 1, character: '#');

        self::assertSame(1, $frame->widths[2], 'Lead cell width must be reset to the fill character width');
        self::assertSame(1, $frame->widths[3], 'Continuation cell width must be reset to the fill character width');
    }

    #[Test]
    public function fillRectCuttingWideCharactersBlanksOrphansAtBothEdges(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{4E16}\u{4E16}");

        $frame->fillRect(1, 0, 2, 1, character: '#');

        self::assertSame(' ', $frame->characters[0], 'Lead cut at rect left edge must be blanked');
        self::assertSame(1, $frame->widths[0]);
        self::assertSame('#', $frame->characters[1]);
        self::assertSame('#', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3], 'Continuation cut at rect right edge must be blanked');
        self::assertSame(1, $frame->widths[3]);
    }

    #[Test]
    public function overlayCuttingWideCharactersBlanksOrphansAtBothEdges(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{4E16}\u{4E16}");

        $source = new Frame(2, 1);
        $source->putString(0, 0, 'ab');
        $frame->overlay($source, 1, 0);

        self::assertSame(' ', $frame->characters[0], 'Lead cut at overlay left edge must be blanked');
        self::assertSame(1, $frame->widths[0]);
        self::assertSame('a', $frame->characters[1]);
        self::assertSame('b', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3], 'Continuation cut at overlay right edge must be blanked');
        self::assertSame(1, $frame->widths[3]);
    }

    #[Test]
    public function fillRectDoesNotAffectOutsideArea(): void
    {
        $frame = new Frame(10, 5);
        $frame->fillRect(2, 2, 2, 2, 1, 2, 3, '#');

        self::assertSame(' ', $frame->characters[1 * 10 + 2]);
        self::assertSame(' ', $frame->characters[2 * 10 + 1]);
        self::assertSame(' ', $frame->characters[2 * 10 + 4]);
        self::assertSame(' ', $frame->characters[4 * 10 + 2]);
    }

    #[Test]
    public function fillRectClipsNegativeCoordinates(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(-2, -2, 4, 4, 0, 0, 0, 'X');

        self::assertSame('X', $frame->characters[0 * 5 + 0]);
        self::assertSame('X', $frame->characters[0 * 5 + 1]);
        self::assertSame('X', $frame->characters[1 * 5 + 0]);
        self::assertSame('X', $frame->characters[1 * 5 + 1]);
        self::assertSame(' ', $frame->characters[0 * 5 + 2]);
        self::assertSame(' ', $frame->characters[2 * 5 + 0]);
    }

    #[Test]
    public function fillRectClipsRightAndBottomEdges(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(3, 3, 10, 10, 0, 0, 0, 'X');

        self::assertSame('X', $frame->characters[3 * 5 + 3]);
        self::assertSame('X', $frame->characters[3 * 5 + 4]);
        self::assertSame('X', $frame->characters[4 * 5 + 3]);
        self::assertSame('X', $frame->characters[4 * 5 + 4]);
        self::assertSame(' ', $frame->characters[3 * 5 + 2]);
        self::assertSame(' ', $frame->characters[2 * 5 + 3]);
    }

    #[Test]
    public function fillRectWithZeroWidthDoesNothing(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(0, 0, 0, 5, 1, 1, 1, 'X');
        for ($i = 0; $i < $frame->size; ++$i) {
            self::assertSame(' ', $frame->characters[$i]);
        }
    }

    #[Test]
    public function fillRectWithZeroHeightDoesNothing(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(0, 0, 5, 0, 1, 1, 1, 'X');
        for ($i = 0; $i < $frame->size; ++$i) {
            self::assertSame(' ', $frame->characters[$i]);
        }
    }

    #[Test]
    public function fillRectMarksDirtyRowsForAffectedRows(): void
    {
        $frame = new Frame(10, 5);
        $frame->fillRect(0, 1, 3, 2);

        self::assertFalse($frame->isRowDirty(0));
        self::assertTrue($frame->isRowDirty(1));
        self::assertTrue($frame->isRowDirty(2));
        self::assertFalse($frame->isRowDirty(3));
    }

    #[Test]
    public function fillRectDoesNotMarkDirtyRowsOutsideClippedArea(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(-2, -2, 4, 4);
        self::assertTrue($frame->isRowDirty(0));
        self::assertTrue($frame->isRowDirty(1));
        self::assertFalse($frame->isRowDirty(2));
    }

    // ── overlay (L248-288) ──

    #[Test]
    public function overlaySkipsEntirelyBlankCells(): void
    {
        $destination = new Frame(10, 5);
        $destination->putChar(2, 1, 'X', 1, 2, 3);

        $source = new Frame(5, 3);

        $destination->overlay($source, 0, 0);
        self::assertSame('X', $destination->characters[1 * 10 + 2]);
        self::assertSame(1, $destination->foregrounds[1 * 10 + 2]);
    }

    #[Test]
    public function overlayCopiesCellWithNonDefaultForeground(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 1);
        $source->putChar(0, 0, 'A', 1);

        $destination->overlay($source, 0, 0);
        self::assertSame('A', $destination->characters[0]);
        self::assertSame(1, $destination->foregrounds[0]);
    }

    #[Test]
    public function overlayCopiesCellWithNonDefaultBackground(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 1);
        $source->putChar(0, 0, ' ', 0, 1);

        $destination->overlay($source, 0, 0);
        self::assertSame(1, $destination->backgrounds[0]);
    }

    #[Test]
    public function overlayCopiesCellWithNonDefaultAttributes(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 1);
        $source->putChar(0, 0, ' ', 0, 0, Attr::BOLD);

        $destination->overlay($source, 0, 0);
        self::assertSame(Attr::BOLD, $destination->attributes[0]);
    }

    #[Test]
    public function overlayCopiesCellWithNonSpaceCharacter(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 1);
        $source->putChar(0, 0, '#');

        $destination->overlay($source, 0, 0);
        self::assertSame('#', $destination->characters[0]);
    }

    #[Test]
    public function overlayClipsSourceBeyondDestinationRight(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(3, 1);
        $source->putString(0, 0, 'ABC');

        $destination->overlay($source, 4, 0);
        self::assertSame('A', $destination->characters[4]);
        self::assertSame(' ', $destination->characters[3]);
    }

    #[Test]
    public function overlayClipsSourceBeyondDestinationBottom(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(1, 3);
        $source->putChar(0, 0, 'A');
        $source->putChar(0, 1, 'B');
        $source->putChar(0, 2, 'C');

        $destination->overlay($source, 0, 4);
        self::assertSame('A', $destination->characters[4 * 5 + 0]);
        self::assertSame(' ', $destination->characters[3 * 5 + 0]);
    }

    #[Test]
    public function overlayWithNegativeOffsetClipsSourceLeft(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(3, 1);
        $source->putString(0, 0, 'ABC');

        $destination->overlay($source, -1, 0);
        self::assertSame('B', $destination->characters[0]);
        self::assertSame('C', $destination->characters[1]);
    }

    #[Test]
    public function overlayWithNegativeOffsetClipsSourceTop(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(1, 3);
        $source->putChar(0, 0, 'A');
        $source->putChar(0, 1, 'B');
        $source->putChar(0, 2, 'C');

        $destination->overlay($source, 0, -1);
        self::assertSame('B', $destination->characters[0]);
        self::assertSame('C', $destination->characters[5]);
    }

    #[Test]
    public function overlayMarksDirtyRowOnlyWhenContentCopied(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 3);
        $source->putChar(1, 1, 'X');

        $destination->overlay($source, 0, 0);

        self::assertFalse($destination->isRowDirty(0), 'Row with all blank cells should not be dirty');
        self::assertTrue($destination->isRowDirty(1), 'Row with copied content should be dirty');
        self::assertFalse($destination->isRowDirty(2), 'Row with all blank cells should not be dirty');
    }

    #[Test]
    public function overlayDoesNotMarkDirtyRowWhenAllCellsAreBlank(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(3, 2);

        $destination->overlay($source, 0, 0);

        self::assertFalse($destination->isRowDirty(0));
        self::assertFalse($destination->isRowDirty(1));
    }

    // ── markAllDirty (L349-353) ──

    #[Test]
    public function markAllDirtyMarksEveryRowAndOnlyValidRows(): void
    {
        $frame = new Frame(5, 3);
        $frame->markAllDirty();

        self::assertTrue($frame->isRowDirty(0));
        self::assertTrue($frame->isRowDirty(1));
        self::assertTrue($frame->isRowDirty(2));
        self::assertCount(3, $frame->dirtyRows);
    }

    // ── Index calculation (y * width + x) ──

    #[Test]
    public function putCharComputesCorrectIndex(): void
    {
        $frame = new Frame(10, 5);
        $frame->putChar(7, 3, 'Q', 1, 2, 3);

        $expectedIndex = 3 * 10 + 7;
        self::assertSame('Q', $frame->characters[$expectedIndex]);
        self::assertSame(1, $frame->foregrounds[$expectedIndex]);
        self::assertSame(2, $frame->backgrounds[$expectedIndex]);
        self::assertSame(3, $frame->attributes[$expectedIndex]);

        self::assertSame(' ', $frame->characters[$expectedIndex - 1]);
        self::assertSame(' ', $frame->characters[$expectedIndex + 1]);
    }

    // ── putAnsi edge cases for CSI sequence parsing ──

    #[Test]
    public function putAnsiEscapeSequenceWithoutBracketIsSkipped(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\e[31mB");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
    }

    #[Test]
    public function putAnsiPreservesStyleAcrossCharacters(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[1;31mABC");

        $redForeground = Color::ansi(1)->value;
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($redForeground, $frame->foregrounds[$i]);
            self::assertSame(Attr::BOLD, $frame->attributes[$i]);
        }
    }

    #[Test]
    public function putAnsiResetClearsStyle(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[31mA\e[0mB");

        self::assertNotSame(0, $frame->foregrounds[0]);
        self::assertSame(0, $frame->foregrounds[1]);
        self::assertSame(0, $frame->attributes[1]);
    }

    #[Test]
    public function putAnsiSetsBackgroundAndAttributes(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[1;42mA");

        $greenBackground = Color::ansi(2)->value;
        self::assertSame($greenBackground, $frame->backgrounds[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    // ── putString index correctness ──

    #[Test]
    public function putStringWritesCorrectForegroundBackgroundAttributes(): void
    {
        $frame = new Frame(10, 3);
        $foreground = Color::ansi(5)->value;
        $background = Color::ansi(6)->value;
        $frame->putString(2, 1, 'AB', $foreground, $background, Attr::UNDERLINE);

        $baseIndex = 1 * 10 + 2;
        self::assertSame('A', $frame->characters[$baseIndex]);
        self::assertSame($foreground, $frame->foregrounds[$baseIndex]);
        self::assertSame($background, $frame->backgrounds[$baseIndex]);
        self::assertSame(Attr::UNDERLINE, $frame->attributes[$baseIndex]);
        self::assertSame('B', $frame->characters[$baseIndex + 1]);
        self::assertSame($foreground, $frame->foregrounds[$baseIndex + 1]);
    }

    // ── putAnsi wide char placeholder style ──

    #[Test]
    public function putAnsiWideCharPlaceholderCopiesStyle(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\e[1;31m\u{4E16}");

        $redForeground = Color::ansi(1)->value;
        self::assertSame($redForeground, $frame->foregrounds[0]);
        self::assertSame($redForeground, $frame->foregrounds[1]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[1]);
        self::assertSame('', $frame->characters[1]);
    }

    // ── fillRect at exact buffer boundaries ──

    #[Test]
    public function fillRectExactBufferSize(): void
    {
        $frame = new Frame(5, 3);
        $frame->fillRect(0, 0, 5, 3, 1, 2, 3, '#');

        for ($i = 0; $i < $frame->size; ++$i) {
            self::assertSame('#', $frame->characters[$i]);
            self::assertSame(1, $frame->foregrounds[$i]);
            self::assertSame(2, $frame->backgrounds[$i]);
            self::assertSame(3, $frame->attributes[$i]);
        }
        for ($row = 0; $row < 3; ++$row) {
            self::assertTrue($frame->isRowDirty($row));
        }
    }

    #[Test]
    public function fillRectSingleCell(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(2, 3, 1, 1, 1, 2, 3, 'X');

        $index = 3 * 5 + 2;
        self::assertSame('X', $frame->characters[$index]);
        self::assertSame(1, $frame->foregrounds[$index]);
        self::assertSame(' ', $frame->characters[$index - 1]);
        self::assertSame(' ', $frame->characters[$index + 1]);
    }

    #[Test]
    public function fillRectRepeatsWideGraphemesAsLeadAndContinuationCells(): void
    {
        $frame = new Frame(5, 1);

        $frame->fillRect(0, 0, 5, 1, 11, 12, 13, "\u{65E5}");

        self::assertSame(["\u{65E5}", '', "\u{65E5}", '', ' '], $frame->characters);
        self::assertSame([2, 0, 2, 0, 1], $frame->widths);
        self::assertSame([11, 11, 11, 11, 11], $frame->foregrounds);
        self::assertSame([12, 12, 12, 12, 12], $frame->backgrounds);
        self::assertSame([13, 13, 13, 13, 13], $frame->attributes);
    }

    #[Test]
    public function fillRectWideGraphemeExactFitStaysInBoundsAndMarksTheRowDirty(): void
    {
        $frame = new Frame(2, 1);

        $frame->fillRect(0, 0, 2, 1, character: "\u{65E5}");

        self::assertSame(["\u{65E5}", ''], $frame->characters);
        self::assertSame([2, 0], $frame->widths);
        self::assertSame([0 => true], $frame->dirtyRows);
    }

    #[Test]
    public function fillRectWithAZeroWidthGraphemeIsANoop(): void
    {
        $frame = new Frame(3, 1);

        $frame->fillRect(0, 0, 3, 1, character: "\u{0300}");

        self::assertSame([' ', ' ', ' '], $frame->characters);
        self::assertSame([1, 1, 1], $frame->widths);
        self::assertSame([], $frame->dirtyRows);
    }

    // ── overlay full copy of non-blank source ──

    #[Test]
    public function overlayFullCopyWithAllProperties(): void
    {
        $destination = new Frame(10, 5);
        $source = new Frame(2, 2);
        $source->putChar(0, 0, 'A', 1, 2, 3);
        $source->putChar(1, 0, 'B', 4, 5, 6);
        $source->putChar(0, 1, 'C', 7, 8, 9);
        $source->putChar(1, 1, 'D', 10, 11, 12);

        $destination->overlay($source, 3, 2);

        $indexA = 2 * 10 + 3;
        self::assertSame('A', $destination->characters[$indexA]);
        self::assertSame(1, $destination->foregrounds[$indexA]);
        self::assertSame(2, $destination->backgrounds[$indexA]);
        self::assertSame(3, $destination->attributes[$indexA]);

        $indexD = 3 * 10 + 4;
        self::assertSame('D', $destination->characters[$indexD]);
        self::assertSame(10, $destination->foregrounds[$indexD]);
        self::assertSame(11, $destination->backgrounds[$indexD]);
        self::assertSame(12, $destination->attributes[$indexD]);
    }

    // ── Additional mutant-killing tests ──

    #[Test]
    public function putCharAtXEqualsWidthMinus1Succeeds(): void
    {
        $frame = new Frame(5, 3);
        $frame->putChar(4, 0, 'Z');
        self::assertSame('Z', $frame->characters[4]);
    }

    #[Test]
    public function putCharAtYEqualsHeightMinus1Succeeds(): void
    {
        $frame = new Frame(5, 3);
        $frame->putChar(0, 2, 'Z');
        self::assertSame('Z', $frame->characters[2 * 5]);
    }

    #[Test]
    public function putStringAtYEqualsHeightMinus1Succeeds(): void
    {
        $frame = new Frame(10, 3);
        $consumed = $frame->putString(0, 2, 'Hi');
        self::assertSame(2, $consumed);
        self::assertSame('H', $frame->characters[2 * 10]);
    }

    #[Test]
    public function putStringAtExactWidthBreaks(): void
    {
        $frame = new Frame(5, 1);
        $consumed = $frame->putString(0, 0, 'ABCDE');
        self::assertSame(5, $consumed);
        self::assertSame('E', $frame->characters[4]);
    }

    #[Test]
    public function putStringColumnExactlyAtWidthStopsLoop(): void
    {
        $frame = new Frame(3, 1);
        $consumed = $frame->putString(0, 0, 'ABCDEF');
        self::assertSame(3, $consumed);
        self::assertSame('C', $frame->characters[2]);
    }

    #[Test]
    public function putAnsiColumnIncrementsByCharWidth(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\u{4E16}B");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame("\u{4E16}", $frame->characters[1]);
        self::assertSame('', $frame->characters[2]);
        self::assertSame('B', $frame->characters[3]);
        self::assertSame(4, $finalX);
    }

    #[Test]
    public function putAnsiNewlineStopsAtHeight(): void
    {
        $frame = new Frame(5, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\nB");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalY);
    }

    #[Test]
    public function putAnsiMultipleNewlines(): void
    {
        $frame = new Frame(10, 5);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\n\n\nA");
        self::assertSame('A', $frame->characters[3 * 10]);
        self::assertSame(1, $finalX);
        self::assertSame(3, $finalY);
    }

    #[Test]
    public function putAnsiEscWithoutMTerminatorScansToEndOfSequence(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\e[2JA");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiIncompleteEscAtEndOfString(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\e[");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiSgrParseSubstringIsCorrect(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[1;4mA");

        self::assertSame(Attr::BOLD | Attr::UNDERLINE, $frame->attributes[0]);
    }

    #[Test]
    public function putAnsiPositionAdvancesCorrectlyAfterEscape(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[31mAB\e[0mCD");

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame('D', $frame->characters[3]);
    }

    #[Test]
    public function fillRectWidthOneColumn(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(2, 0, 1, 5, 0, 0, 0, '|');

        for ($row = 0; $row < 5; ++$row) {
            self::assertSame('|', $frame->characters[$row * 5 + 2]);
            self::assertSame(' ', $frame->characters[$row * 5 + 1]);
            self::assertSame(' ', $frame->characters[$row * 5 + 3]);
        }
    }

    #[Test]
    public function fillRectHeightOneRow(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(0, 2, 5, 1, 0, 0, 0, '-');

        for ($column = 0; $column < 5; ++$column) {
            self::assertSame('-', $frame->characters[2 * 5 + $column]);
        }
        self::assertSame(' ', $frame->characters[1 * 5]);
        self::assertSame(' ', $frame->characters[3 * 5]);
    }

    #[Test]
    public function fillRectNegativeXOnlyWritesVisiblePart(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(-3, 0, 5, 1, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function fillRectNegativeYOnlyWritesVisiblePart(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(0, -3, 1, 5, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[10]);
    }

    #[Test]
    public function overlaySourceEntirelyOutsideDestinationRightIsNoop(): void
    {
        $destination = new Frame(5, 5);
        $destination->putChar(0, 0, 'X');
        $destination->clearDirty();

        $source = new Frame(3, 1);
        $source->putString(0, 0, 'ABC');

        $destination->overlay($source, 5, 0);
        self::assertSame('X', $destination->characters[0]);
        self::assertFalse($destination->isRowDirty(0));
    }

    #[Test]
    public function overlaySourceEntirelyOutsideDestinationBottomIsNoop(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'A');

        $destination->overlay($source, 0, 5);
        for ($i = 0; $i < $destination->size; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    #[Test]
    public function overlaySourceEntirelyAboveDestinationIsNoop(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(1, 2);
        $source->putChar(0, 0, 'A');
        $source->putChar(0, 1, 'B');

        $destination->overlay($source, 0, -2);
        for ($i = 0; $i < $destination->size; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    #[Test]
    public function overlaySourceEntirelyLeftOfDestinationIsNoop(): void
    {
        $destination = new Frame(5, 5);
        $source = new Frame(2, 1);
        $source->putString(0, 0, 'AB');

        $destination->overlay($source, -2, 0);
        for ($i = 0; $i < $destination->size; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    #[Test]
    public function putCharWritesAllFourArrays(): void
    {
        $frame = new Frame(3, 3);
        $frame->putChar(1, 1, 'M', 10, 20, 30);

        $index = 1 * 3 + 1;
        self::assertSame('M', $frame->characters[$index]);
        self::assertSame(10, $frame->foregrounds[$index]);
        self::assertSame(20, $frame->backgrounds[$index]);
        self::assertSame(30, $frame->attributes[$index]);

        self::assertSame(' ', $frame->characters[$index - 1]);
        self::assertSame(0, $frame->foregrounds[$index - 1]);
        self::assertSame(' ', $frame->characters[$index + 1]);
        self::assertSame(0, $frame->foregrounds[$index + 1]);
    }

    #[Test]
    public function putStringRowBaseCalculation(): void
    {
        $frame = new Frame(7, 4);
        $frame->putString(3, 2, 'XY', 1);

        $expectedIndex = 2 * 7 + 3;
        self::assertSame('X', $frame->characters[$expectedIndex]);
        self::assertSame('Y', $frame->characters[$expectedIndex + 1]);
        self::assertSame(1, $frame->foregrounds[$expectedIndex]);
        self::assertSame(' ', $frame->characters[$expectedIndex - 1]);
    }

    #[Test]
    public function putAnsiRowBaseCalculation(): void
    {
        $frame = new Frame(7, 4);
        $frame->putAnsi(3, 2, 'XY');

        $expectedIndex = 2 * 7 + 3;
        self::assertSame('X', $frame->characters[$expectedIndex]);
        self::assertSame('Y', $frame->characters[$expectedIndex + 1]);
    }

    #[Test]
    public function putAnsiTabWithParserColors(): void
    {
        $frame = new Frame(20, 1);
        $parser = new SgrParser();
        $parser->foreground = 5;
        $parser->background = 10;
        $parser->attributes = Attr::ITALIC;

        $frame->putAnsi(0, 0, "\tA", $parser);

        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(' ', $frame->characters[$i]);
            self::assertSame(5, $frame->foregrounds[$i]);
            self::assertSame(10, $frame->backgrounds[$i]);
            self::assertSame(Attr::ITALIC, $frame->attributes[$i]);
        }
        self::assertSame('A', $frame->characters[8]);
    }

    #[Test]
    public function putAnsiTabStopCalculation(): void
    {
        $frame = new Frame(30, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "1234567\t");
        self::assertSame(8, $finalX);

        $buffer2 = new Frame(30, 1);
        [$finalX2, $finalY2] = $buffer2->putAnsi(0, 0, "12345678\t");
        self::assertSame(16, $finalX2);
    }

    #[Test]
    public function putAnsiCarriageReturnDoesNotAdvanceY(): void
    {
        $frame = new Frame(10, 3);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "ABC\r");
        self::assertSame(0, $finalX);
        self::assertSame(0, $finalY);
    }

    #[Test]
    public function putAnsiControlChar0x01IsSkipped(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\x01A");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiControlChar0x1FIsSkipped(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "\x1FA");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiByteExactly0x20IsNotControl(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, ' A');
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('A', $frame->characters[1]);
        self::assertSame(2, $finalX);
    }

    #[Test]
    public function putAnsiAsciiByte0x7EIsNormal(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, '~');
        self::assertSame('~', $frame->characters[0]);
    }

    #[Test]
    public function putAnsiTwoByteSetsCorrectByteLength(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\u{00C0}B");
        self::assertSame("\u{00C0}", $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
    }

    #[Test]
    public function putAnsiThreeByteSetsCorrectByteLength(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\u{2603}B");
        self::assertSame("\u{2603}", $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
    }

    #[Test]
    public function putAnsiFourByteSetsCorrectByteLength(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\u{1F600}B");
        self::assertSame("\u{1F600}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame('B', $frame->characters[2]);
    }

    #[Test]
    public function putAnsiWritesCharacterAtColumn0Row0(): void
    {
        $frame = new Frame(10, 5);
        $frame->putAnsi(0, 0, 'A');

        self::assertSame('A', $frame->characters[0]);
        self::assertTrue($frame->isRowDirty(0));
    }

    #[Test]
    public function putAnsiNegativeColumnCharactersAreNotWritten(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(-3, 0, 'AB');

        self::assertSame(' ', $frame->characters[0]);
        self::assertFalse($frame->isRowDirty(0));
    }

    #[Test]
    public function putAnsiNegativeYNegativeColumnDoesNotWrite(): void
    {
        $frame = new Frame(10, 5);
        [$finalX, $finalY] = $frame->putAnsi(-2, -1, 'AB');
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function putAnsiStartingAtNonZeroX(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(5, 0, 'AB');
        self::assertSame('A', $frame->characters[5]);
        self::assertSame('B', $frame->characters[6]);
        self::assertSame(7, $finalX);
    }

    #[Test]
    public function putStyledReturnsSameAsPutString(): void
    {
        $buffer1 = new Frame(10, 1);
        $buffer2 = new Frame(10, 1);
        $style = new Style(foreground: Color::ansi(1), background: Color::ansi(2), attributes: Attr::BOLD);

        $consumed1 = $buffer1->putStyled(2, 0, 'ABC', $style);
        $consumed2 = $buffer2->putString(2, 0, 'ABC', $style->foregroundValue(), $style->backgroundValue(), $style->attributes);

        self::assertSame($consumed2, $consumed1);
        self::assertSame($buffer2->characters, $buffer1->characters);
        self::assertSame($buffer2->foregrounds, $buffer1->foregrounds);
        self::assertSame($buffer2->backgrounds, $buffer1->backgrounds);
        self::assertSame($buffer2->attributes, $buffer1->attributes);
    }

    #[Test]
    public function overlayIndexCalculationUsesSourceAndDestinationWidths(): void
    {
        $destination = new Frame(8, 4);
        $source = new Frame(3, 2);
        $source->putChar(2, 1, 'Q', 1);

        $destination->overlay($source, 4, 2);

        $expectedIndex = 3 * 8 + 6;
        self::assertSame('Q', $destination->characters[$expectedIndex]);
        self::assertSame(1, $destination->foregrounds[$expectedIndex]);

        self::assertSame(' ', $destination->characters[$expectedIndex - 1]);
        self::assertSame(' ', $destination->characters[$expectedIndex + 1]);
    }

    #[Test]
    public function putAnsiEscFollowedByBracketButNoParams(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\e[mA");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function putStringDoesNotMarkDirtyRowWhenYIsOutOfBounds(): void
    {
        $frame = new Frame(10, 3);
        $frame->putString(0, -1, 'Hello');
        for ($row = 0; $row < 3; ++$row) {
            self::assertFalse($frame->isRowDirty($row));
        }

        $frame->putString(0, 3, 'Hello');
        for ($row = 0; $row < 3; ++$row) {
            self::assertFalse($frame->isRowDirty($row));
        }
    }

    #[Test]
    public function putStringReturnValueIsColumnMinusX(): void
    {
        $frame = new Frame(20, 1);
        $consumed = $frame->putString(5, 0, 'ABC');
        self::assertSame(3, $consumed);
    }

    #[Test]
    public function putStringReturnValueWithNegativeX(): void
    {
        $frame = new Frame(20, 1);
        $consumed = $frame->putString(-2, 0, 'ABCDE');
        self::assertSame(5, $consumed);
    }

    #[Test]
    public function fillRectStartXIsMaxOf0AndX(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(-1, 0, 3, 1, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function fillRectStartYIsMaxOf0AndY(): void
    {
        $frame = new Frame(5, 5);
        $frame->fillRect(0, -1, 1, 3, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[10]);
    }

    #[Test]
    public function fillRectEndXIsMinOfWidthAndXPlusWidth(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(3, 0, 5, 1, 0, 0, 0, 'X');
        self::assertSame(' ', $frame->characters[2]);
        self::assertSame('X', $frame->characters[3]);
        self::assertSame('X', $frame->characters[4]);
    }

    #[Test]
    public function fillRectEndYIsMinOfHeightAndYPlusHeight(): void
    {
        $frame = new Frame(1, 5);
        $frame->fillRect(0, 3, 1, 5, 0, 0, 0, 'X');
        self::assertSame(' ', $frame->characters[2]);
        self::assertSame('X', $frame->characters[3]);
        self::assertSame('X', $frame->characters[4]);
    }

    // ── Mutant-killing: putChar bounds (L47) ──

    #[Test]
    public function putCharAtXWidthMinus1WritesAtYHeight(): void
    {
        $frame = new Frame(5, 3);
        $frame->putChar(4, 2, 'Z', 1);
        self::assertSame('Z', $frame->characters[2 * 5 + 4]);
        self::assertSame(1, $frame->foregrounds[2 * 5 + 4]);
    }

    #[Test]
    public function putCharAtXWidthIsRejected(): void
    {
        $frame = new Frame(5, 3);
        $snapshot = $frame->characters;
        $frame->putChar(5, 0, 'Z', 1);
        self::assertSame($snapshot, $frame->characters);
        self::assertFalse($frame->isRowDirty(0));
    }

    #[Test]
    public function putCharAtYHeightIsRejected(): void
    {
        $frame = new Frame(5, 3);
        $snapshot = $frame->characters;
        $frame->putChar(0, 3, 'Z', 1);
        self::assertSame($snapshot, $frame->characters);
    }

    #[Test]
    public function putCharPositiveXNegativeYIsRejected(): void
    {
        $frame = new Frame(5, 3);
        $frame->putChar(2, -1, 'Z', 1);
        self::assertSame(' ', $frame->characters[2]);
        self::assertFalse($frame->isRowDirty(0));
    }

    #[Test]
    public function putCharNegativeXPositiveYIsRejected(): void
    {
        $frame = new Frame(5, 3);
        $frame->putChar(-1, 2, 'Z', 1);
        for ($i = 0; $i < $frame->size; ++$i) {
            self::assertSame(' ', $frame->characters[$i]);
        }
    }

    // ── Mutant-killing: putChar dirty tracking uses true (L56) ──

    #[Test]
    public function putCharDirtyRowIsActuallyTrue(): void
    {
        $frame = new Frame(5, 3);
        $frame->putChar(0, 1, 'A');
        self::assertTrue($frame->isRowDirty(1));
        self::assertSame(true, $frame->dirtyRows[1]);
    }

    // ── Mutant-killing: putChar wide char at second-to-last column (L59) ──

    #[Test]
    public function putCharWideCharAtSecondToLastColumnWritesPlaceholder(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(3, 0, "\u{4E16}", 1, 2, 3);
        self::assertSame("\u{4E16}", $frame->characters[3]);
        self::assertSame('', $frame->characters[4]);
        self::assertSame(1, $frame->foregrounds[4]);
        self::assertSame(2, $frame->backgrounds[4]);
        self::assertSame(3, $frame->attributes[4]);
    }

    #[Test]
    public function putCharWideCharAtExactLastColumnIsSkipped(): void
    {
        $frame = new Frame(5, 2);
        $frame->putChar(4, 0, "\u{4E16}", 1, 2, 3);
        self::assertSame(' ', $frame->characters[4], 'Wide char at exact last column must be skipped');
        self::assertSame(0, $frame->foregrounds[4]);
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame(0, $frame->foregrounds[3]);
        self::assertSame(' ', $frame->characters[5], 'Next row first cell must not be corrupted');
        self::assertSame(0, $frame->foregrounds[5]);
    }

    #[Test]
    public function putCharWideCharAtThirdToLastColumnWritesPlaceholder(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(2, 0, "\u{4E16}", 1, 2, 3);
        self::assertSame("\u{4E16}", $frame->characters[2]);
        self::assertSame('', $frame->characters[3]);
        self::assertSame(1, $frame->foregrounds[3]);
    }

    // ── Mutant-killing: putString default foreground param (L71) ──

    #[Test]
    public function putStringDefaultForegroundIsZero(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'A');
        self::assertSame(0, $frame->foregrounds[0]);
    }

    // ── Mutant-killing: putString break vs continue (L83) ──

    #[Test]
    public function putStringBreakAtWidthReturnsSameAsVisibleColumns(): void
    {
        $frame = new Frame(3, 1);
        $consumed = $frame->putString(0, 0, 'ABCDE');
        self::assertSame(3, $consumed);
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
    }

    #[Test]
    public function putStringBreakStopsReturnValueAtWidth(): void
    {
        $frame = new Frame(4, 1);
        $consumed = $frame->putString(0, 0, 'ABCDEFGH');
        self::assertSame(4, $consumed);
    }

    // ── Mutant-killing: putString charWidth===0 (L87) ──

    #[Test]
    public function putStringCombiningMarksKeptWithBases(): void
    {
        $frame = new Frame(10, 1);
        $consumed = $frame->putString(0, 0, "A\u{0300}B\u{0301}C");
        self::assertSame("A\u{0300}", $frame->characters[0]);
        self::assertSame("B\u{0301}", $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame(3, $consumed);
    }

    // ── Mutant-killing: putString wide char at second-to-last column (L98) ──

    #[Test]
    public function putStringWideCharSecondToLastColumnWritesPlaceholder(): void
    {
        $frame = new Frame(5, 1);
        $frame->putString(3, 0, "\u{4E16}", 1, 2, 3);
        self::assertSame("\u{4E16}", $frame->characters[3]);
        self::assertSame('', $frame->characters[4]);
        self::assertSame(1, $frame->foregrounds[4]);
        self::assertSame(2, $frame->backgrounds[4]);
        self::assertSame(3, $frame->attributes[4]);
    }

    #[Test]
    public function putStringWideCharExactLastColumnIsSkipped(): void
    {
        $frame = new Frame(5, 2);
        $consumed = $frame->putString(4, 0, "\u{4E16}", 1, 2, 3);
        self::assertSame(2, $consumed, 'Skipped wide char must still advance the cursor by its full width');
        self::assertSame(' ', $frame->characters[4], 'Wide char at exact last column must be skipped');
        self::assertSame(0, $frame->foregrounds[4]);
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame(0, $frame->foregrounds[3]);
        self::assertSame(' ', $frame->characters[5], 'Next row first cell must not be corrupted');
        self::assertSame(0, $frame->foregrounds[5]);
    }

    // ── Mutant-killing: putString dirty uses true (L110) ──

    #[Test]
    public function putStringDirtyRowIsActuallyTrue(): void
    {
        $frame = new Frame(10, 3);
        $frame->putString(0, 1, 'X');
        self::assertSame(true, $frame->dirtyRows[1]);
    }

    // ── Mutant-killing: putAnsi height boundary (L140) ──

    #[Test]
    public function putAnsiYAtExactHeightDoesNotWrite(): void
    {
        $frame = new Frame(5, 2);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\nB\nC");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[5]);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(' ', $frame->characters[2 * 5 + $i] ?? ' ');
        }
    }

    // ── Mutant-killing: putAnsi escape detection (L143) ──

    #[Test]
    public function putAnsiEscapeAtEndOfString(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "A\e[31m");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiEscapeSequenceAppliedToSubsequentChars(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\e[31mAB");
        $redForeground = Color::ansi(1)->value;
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame($redForeground, $frame->foregrounds[0]);
        self::assertSame($redForeground, $frame->foregrounds[1]);
    }

    #[Test]
    public function putAnsiEscapeAtLastByte(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $_] = $frame->putAnsi(0, 0, "AB\e");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
    }

    // ── Mutant-killing: putAnsi strpos offset (L144) ──

    #[Test]
    public function putAnsiSgrContentStartsAfterBracket(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\e[38;5;196mX\e[0m");
        self::assertSame('X', $frame->characters[0]);
        self::assertNotSame(0, $frame->foregrounds[0]);
    }

    // ── Mutant-killing: putAnsi SGR parse length (L146) ──

    #[Test]
    public function putAnsiSgrParameterParsedCorrectly(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\e[31mA");
        self::assertSame(Color::ansi(1)->value, $frame->foregrounds[0]);
    }

    #[Test]
    public function putAnsiSgrMultipleParametersParsedCorrectly(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\e[1;31;42mA");
        self::assertSame(Color::ansi(1)->value, $frame->foregrounds[0]);
        self::assertSame(Color::ansi(2)->value, $frame->backgrounds[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    #[Test]
    public function putAnsiSgrWithLongParams(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\e[38;2;255;0;0mR");
        self::assertSame('R', $frame->characters[0]);
        self::assertNotSame(0, $frame->foregrounds[0]);
    }

    // ── Mutant-killing: putAnsi non-SGR scan (L150-154) ──

    #[Test]
    public function putAnsiNonSgrSequenceSkippedCorrectly(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $_] = $frame->putAnsi(0, 0, "\e[2JA");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    #[Test]
    public function putAnsiNonSgrAtSymbolTerminator(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $_] = $frame->putAnsi(0, 0, "\e[?25hA");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    // ── Mutant-killing: putAnsi match arm byte length (L186-191) ──

    #[Test]
    public function putAnsiAsciiCharacterUsesOneByte(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, 'AB');
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
    }

    #[Test]
    public function putAnsiC1ControlIsDiscardedWithoutConsumingACell(): void
    {
        $frame = new Frame(10, 1);
        $text = "\xC2\x80Z";
        $frame->putAnsi(0, 0, $text);
        self::assertSame('Z', $frame->characters[0]);
    }

    #[Test]
    public function putAnsiThreeByteCharacterParsedCorrectly(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\u{4E16}Z");
        self::assertSame("\u{4E16}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame('Z', $frame->characters[2]);
    }

    #[Test]
    public function putAnsiFourByteCharacterParsedCorrectly(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\u{1F600}Z");
        self::assertSame("\u{1F600}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame('Z', $frame->characters[2]);
    }

    // ── Mutant-killing: putAnsi zero-width (L197) ──

    #[Test]
    public function putAnsiZeroWidthCharacterDoesNotAdvanceColumn(): void
    {
        $frame = new Frame(10, 1);
        [$finalX, $_] = $frame->putAnsi(0, 0, "A\u{0300}B");
        self::assertSame("A\u{0300}", $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame(2, $finalX);
    }

    // ── Mutant-killing: putAnsi continue vs break at width (L201) ──

    #[Test]
    public function putAnsiContinueAtWidthSkipsRemainingChars(): void
    {
        $frame = new Frame(3, 2);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "ABCDE\nFG");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame('F', $frame->characters[3]);
        self::assertSame('G', $frame->characters[4]);
        self::assertSame(2, $finalX);
        self::assertSame(1, $finalY);
    }

    // ── Mutant-killing: putAnsi wide char boundary (L212) ──

    #[Test]
    public function putAnsiWideCharSecondToLastColumnWritesPlaceholder(): void
    {
        $frame = new Frame(5, 1);
        $frame->putAnsi(0, 0, "AB\u{4E16}");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame("\u{4E16}", $frame->characters[2]);
        self::assertSame('', $frame->characters[3]);
    }

    #[Test]
    public function putAnsiWideCharExactLastColumnIsSkipped(): void
    {
        $frame = new Frame(5, 2);
        [$finalX, $finalY] = $frame->putAnsi(0, 0, "ABCD\u{4E16}");
        self::assertSame(6, $finalX, 'Skipped wide char must still advance column by its full width');
        self::assertSame(0, $finalY);
        self::assertSame('D', $frame->characters[3]);
        self::assertSame(' ', $frame->characters[4], 'Wide char at exact last column must be skipped');
        self::assertSame(' ', $frame->characters[5], 'Next row first cell must not be corrupted');
        self::assertSame(0, $frame->foregrounds[5]);
    }

    #[Test]
    public function putAnsiContinuesProcessingAfterSkippingWideCharAtLastColumn(): void
    {
        $frame = new Frame(5, 2);
        $frame->putAnsi(0, 0, "ABCD\u{4E16}\nQ");

        self::assertSame('Q', $frame->characters[5], 'After skip-and-newline, putAnsi must continue processing on the next row');
    }

    #[Test]
    public function widthsArrayIsInitializedToOneForEveryCell(): void
    {
        $frame = new Frame(4, 3);

        self::assertCount(12, $frame->widths, 'widths must have exactly width*height entries');
        foreach (range(0, 11) as $index) {
            self::assertSame(1, $frame->widths[$index], "widths[$index] must default to 1, not 0/2/missing");
        }
    }

    #[Test]
    public function continuationCellWidthIsExactlyZero(): void
    {
        $frame = new Frame(3, 1);
        $frame->putChar(0, 0, "\u{4E16}");

        self::assertSame(2, $frame->widths[0], 'Wide char cell carries width 2');
        self::assertSame(0, $frame->widths[1], 'Continuation cell width must be exactly 0 (not -1)');
    }

    #[Test]
    public function putStringNonAsciiOverflowDoesNotCorruptNextRow(): void
    {
        $frame = new Frame(3, 2);
        $frame->putString(1, 0, "\u{4E16}A");

        self::assertSame(' ', $frame->characters[3], 'Char past end-of-row must not spill into row 1, col 0');
        self::assertSame(0, $frame->foregrounds[3]);
    }

    #[Test]
    public function putStringLeadingCombiningMarkIsNotWrittenToCell(): void
    {
        $frame = new Frame(3, 1);
        $frame->putString(0, 0, "\u{0300}");

        self::assertSame(' ', $frame->characters[0], 'Lone leading combining mark must not occupy a cell');
        self::assertSame(1, $frame->widths[0], 'Cell width must remain default when only zero-width char was offered');
    }

    #[Test]
    public function putAnsiWideCharAtSecondToLastWithStyle(): void
    {
        $frame = new Frame(5, 1);
        $frame->putAnsi(0, 0, "\e[31mAB\u{4E16}");
        $red = Color::ansi(1)->value;
        self::assertSame('', $frame->characters[3]);
        self::assertSame($red, $frame->foregrounds[3]);
    }

    // ── Mutant-killing: putAnsi dirty uses true (L220) ──

    #[Test]
    public function putAnsiDirtyRowIsActuallyTrue(): void
    {
        $frame = new Frame(10, 3);
        $frame->putAnsi(0, 1, 'A');
        self::assertSame(true, $frame->dirtyRows[1]);
    }

    // ── Mutant-killing: fillRect default params (L228) ──

    #[Test]
    public function fillRectDefaultForegroundIsZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 1, 1);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function fillRectDefaultBackgroundIsZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 1, 1);
        self::assertSame(0, $frame->backgrounds[0]);
    }

    #[Test]
    public function fillRectDefaultAttributesIsZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 1, 1);
        self::assertSame(0, $frame->attributes[0]);
    }

    #[Test]
    public function fillRectExplicitForeground(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 1, 1, 42);
        self::assertSame(42, $frame->foregrounds[0]);
    }

    #[Test]
    public function fillRectExplicitBackground(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 1, 1, 0, 42);
        self::assertSame(42, $frame->backgrounds[0]);
    }

    #[Test]
    public function fillRectExplicitAttributes(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 1, 1, 0, 0, Attr::BOLD);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    // ── Mutant-killing: fillRect clipping max(0,x) / max(0,y) (L230-231) ──

    #[Test]
    public function fillRectMaxStartXClampsToZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(-1, 0, 3, 1, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function fillRectMaxStartYClampsToZero(): void
    {
        $frame = new Frame(1, 5);
        $frame->fillRect(0, -1, 1, 3, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    // ── Mutant-killing: fillRect dirty uses true (L244) ──

    #[Test]
    public function fillRectDirtyRowIsActuallyTrue(): void
    {
        $frame = new Frame(5, 3);
        $frame->fillRect(0, 1, 1, 1);
        self::assertSame(true, $frame->dirtyRows[1]);
    }

    // ── Mutant-killing: overlay default params (L248) ──

    #[Test]
    public function overlayDefaultOffsetXIsZero(): void
    {
        $destination = new Frame(5, 1);
        $source = new Frame(2, 1);
        $source->putChar(0, 0, 'A', 1);
        $destination->overlay($source);
        self::assertSame('A', $destination->characters[0]);
    }

    #[Test]
    public function overlayDefaultOffsetYIsZero(): void
    {
        $destination = new Frame(5, 2);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'A', 1);
        $destination->overlay($source);
        self::assertSame('A', $destination->characters[0]);
        self::assertSame(' ', $destination->characters[5]);
    }

    // ── Mutant-killing: overlay bounds (L252) ──

    #[Test]
    public function overlayDestinationYAtHeightMinus1Succeeds(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'A', 1);
        $destination->overlay($source, 0, 2);
        self::assertSame('A', $destination->characters[2 * 5]);
    }

    #[Test]
    public function overlayDestinationYAtExactHeightSkips(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'A', 1);
        $destination->overlay($source, 0, 3);
        for ($i = 0; $i < $destination->size; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    #[Test]
    public function overlayDestinationYNegativeOneSkips(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'A', 1);
        $destination->overlay($source, 0, -1);
        for ($i = 0; $i < $destination->size; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    // ── Mutant-killing: overlay dirty uses true (L285) ──

    #[Test]
    public function overlayDirtyRowIsActuallyTrue(): void
    {
        $destination = new Frame(10, 3);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'A', 1);
        $destination->overlay($source, 0, 1);
        self::assertSame(true, $destination->dirtyRows[1]);
    }

    // ── Mutant-killing: markAllDirty uses true (L352) ──

    #[Test]
    public function markAllDirtyRowsAreActuallyTrue(): void
    {
        $frame = new Frame(5, 3);
        $frame->markAllDirty();
        for ($row = 0; $row < 3; ++$row) {
            self::assertSame(true, $frame->dirtyRows[$row]);
        }
    }

    // ── Mutant-killing: putChar wide char at last column must not corrupt next row (L59) ──

    #[Test]
    public function putCharWideCharLastColumnIsSkippedAndDoesNotCorruptNextRow(): void
    {
        $frame = new Frame(4, 3);
        $frame->putChar(3, 0, "\u{4E16}", 99, 88, 77);
        self::assertSame(' ', $frame->characters[3], 'Wide char at last column must be skipped');
        self::assertSame(' ', $frame->characters[4], 'Index 4 (row 1, col 0) must remain blank');
        self::assertSame(0, $frame->foregrounds[4]);
        self::assertSame(0, $frame->backgrounds[4]);
        self::assertSame(0, $frame->attributes[4]);
    }

    // ── Mutant-killing: putString zero-width chars must not be written to cells (L87) ──

    #[Test]
    public function putStringZeroWidthCharIsNotWrittenToCell(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{0300}X");
        self::assertSame('X', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
    }

    // ── Mutant-killing: putString wide char at last column must not corrupt next row (L98) ──

    #[Test]
    public function putStringWideCharLastColumnIsSkippedAndDoesNotCorruptNextRow(): void
    {
        $frame = new Frame(4, 3);
        $frame->putString(3, 0, "\u{4E16}", 99, 88, 77);
        self::assertSame(' ', $frame->characters[3], 'Wide char at last column must be skipped');
        self::assertSame(' ', $frame->characters[4], 'Index 4 (row 1, col 0) must remain blank');
        self::assertSame(0, $frame->foregrounds[4]);
        self::assertSame(0, $frame->backgrounds[4]);
        self::assertSame(0, $frame->attributes[4]);
    }

    // ── Mutant-killing: putAnsi SGR length calculation (L146) ──

    #[Test]
    public function putAnsiSgrAfterNonZeroPositionParsedCorrectly(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "Hello\e[31mX");
        self::assertSame('H', $frame->characters[0]);
        self::assertSame(0, $frame->foregrounds[0]);
        self::assertSame('X', $frame->characters[5]);
        self::assertSame(Color::ansi(1)->value, $frame->foregrounds[5]);
    }

    #[Test]
    public function putAnsiSgrSubstringExtractionAtNonZeroPosition(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "ABCD\e[1;4mE");
        self::assertSame('E', $frame->characters[4]);
        self::assertSame(Attr::BOLD | Attr::UNDERLINE, $frame->attributes[4]);
        self::assertSame(0, $frame->attributes[3]);
    }

    // ── Mutant-killing: putAnsi non-SGR scan byte boundary (L151) ──

    #[Test]
    public function putAnsiNonSgrSequenceWithAtTerminator(): void
    {
        $frame = new Frame(20, 1);
        [$finalX, $_] = $frame->putAnsi(0, 0, "\e[@X");
        self::assertSame('X', $frame->characters[0]);
        self::assertSame(1, $finalX);
    }

    // ── Mutant-killing: putAnsi match arm UTF-8 byte detection (L186-191) ──

    #[Test]
    public function putAnsiAsciiByteFollowedByMultibyte(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "A\u{00C0}B\u{4E16}C\u{1F600}D");
        self::assertSame('A', $frame->characters[0]);
        self::assertSame("\u{00C0}", $frame->characters[1]);
        self::assertSame('B', $frame->characters[2]);
        self::assertSame("\u{4E16}", $frame->characters[3]);
        self::assertSame('', $frame->characters[4]);
        self::assertSame('C', $frame->characters[5]);
        self::assertSame("\u{1F600}", $frame->characters[6]);
        self::assertSame('', $frame->characters[7]);
        self::assertSame('D', $frame->characters[8]);
    }

    #[Test]
    public function putAnsiDefaultMatchArmForInvalidByte(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\xFFX");
        self::assertSame('X', $frame->characters[1]);
    }

    // ── Mutant-killing: putAnsi zero-width char in putAnsi (L197) ──

    #[Test]
    public function putAnsiZeroWidthCombiningCharNotWritten(): void
    {
        $frame = new Frame(10, 1);
        $frame->putAnsi(0, 0, "\u{0300}X");
        self::assertSame('X', $frame->characters[0]);
    }

    // ── Mutant-killing: putAnsi wide char at last column (L212) ──

    #[Test]
    public function putAnsiWideCharLastColumnIsSkippedAndDoesNotCorruptNextRow(): void
    {
        $frame = new Frame(4, 3);
        $frame->putAnsi(0, 0, "ABC\u{4E16}");
        self::assertSame(' ', $frame->characters[3], 'Wide char at last column must be skipped');
        self::assertSame(' ', $frame->characters[4], 'Index 4 (row 1, col 0) must remain blank');
        self::assertSame(0, $frame->foregrounds[4]);
    }

    // ── Mutant-killing: fillRect max(0,x) vs max(-1,x) (L230) ──

    #[Test]
    public function fillRectStartXClampsToZeroNotNegativeOne(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 3, 1, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame('X', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
    }

    // ── Mutant-killing: fillRect max(0,y) vs max(-1,y) (L231) ──

    #[Test]
    public function fillRectStartYClampsToZeroNotNegativeOne(): void
    {
        $frame = new Frame(1, 5);
        $frame->fillRect(0, 0, 1, 3, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame('X', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
    }

    // ── Mutant-killing: overlay >= vs > on height (L252) ──

    #[Test]
    public function overlayAtLastRowSucceeds(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 3);
        $source->putChar(0, 0, 'A', 1);
        $source->putChar(0, 1, 'B', 1);
        $source->putChar(0, 2, 'C', 1);
        $destination->overlay($source, 0, 0);
        self::assertSame('A', $destination->characters[0 * 5]);
        self::assertSame('B', $destination->characters[1 * 5]);
        self::assertSame('C', $destination->characters[2 * 5]);
    }

    #[Test]
    public function overlayAtExactHeightRowIsSkipped(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 4);
        $source->putChar(0, 3, 'D', 1);
        $destination->overlay($source, 0, 0);
        self::assertSame(' ', $destination->characters[2 * 5]);
    }

    // ── Mutant-killing: overlay || vs && on bounds (L252) ──

    #[Test]
    public function overlayNegativeDestYSkipsThatRow(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 2);
        $source->putChar(0, 0, 'A', 1);
        $source->putChar(0, 1, 'B', 1);
        $destination->overlay($source, 0, -1);
        self::assertSame('B', $destination->characters[0]);
        for ($i = 5; $i < $destination->size; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    // ── Mutant-killing: putChar default params (L45) ──

    #[Test]
    public function putCharDefaultForegroundIsZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(0, 0, 'A');
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function putCharDefaultBackgroundIsZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(0, 0, 'A');
        self::assertSame(0, $frame->backgrounds[0]);
    }

    #[Test]
    public function putCharDefaultAttributesIsZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->putChar(0, 0, 'A');
        self::assertSame(0, $frame->attributes[0]);
    }

    // ── Mutant-killing: putString default background and attributes params (L71) ──

    #[Test]
    public function putStringDefaultBackgroundIsZero(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'A');
        self::assertSame(0, $frame->backgrounds[0]);
    }

    #[Test]
    public function putStringDefaultAttributesIsZero(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'A');
        self::assertSame(0, $frame->attributes[0]);
    }

    // ── Mutant-killing: putAnsi wide char at column width-2 (L212 column+2 vs column+1) ──

    #[Test]
    public function putAnsiWideCharAtColumnWidthMinus2WritesPlaceholder(): void
    {
        $frame = new Frame(6, 2);
        $frame->putAnsi(0, 0, "ABCD\u{4E16}X");
        self::assertSame("\u{4E16}", $frame->characters[4]);
        self::assertSame('', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[6], 'Next row must not be corrupted');
    }

    // ── Mutant-killing: putAnsi 3-byte UTF-8 with first byte > 0xE4 (L189 bitmask) ──

    #[Test]
    public function putAnsiThreeByteCharWithByteE5(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\u{5B57}Z");
        self::assertSame("\u{5B57}", $frame->characters[0]);
        self::assertSame('', $frame->characters[1]);
        self::assertSame('Z', $frame->characters[2]);
    }

    #[Test]
    public function putAnsiThreeByteCharWithByteEf(): void
    {
        $frame = new Frame(20, 1);
        $frame->putAnsi(0, 0, "\u{FEFF}Z");
        self::assertSame('Z', $frame->characters[0]);
    }

    // ── Mutant-killing: putAnsi 4-byte UTF-8 with first byte 0xF1 (L190 bitmask) ──

    #[Test]
    public function putAnsiFourByteCharWithByteF1(): void
    {
        $frame = new Frame(20, 1);
        $text = "\xF1\x80\x80\x80Z";
        $frame->putAnsi(0, 0, $text);
        self::assertSame('Z', $frame->characters[1] !== '' ? $frame->characters[1] : $frame->characters[2]);
    }

    // ── Mutant-killing: copyRowFrom loop bounds (L313) ──

    #[Test]
    public function copyRowFromCopiesExactlyOneRow(): void
    {
        $source = new Frame(5, 3);
        $source->putString(0, 0, 'AAAAA', 1);
        $source->putString(0, 1, 'BBBBB', 2);
        $source->putString(0, 2, 'CCCCC', 3);

        $destination = new Frame(5, 3);
        $destination->copyRowFrom($source, 1);

        for ($column = 0; $column < 5; ++$column) {
            self::assertSame('B', $destination->characters[1 * 5 + $column]);
            self::assertSame(2, $destination->foregrounds[1 * 5 + $column]);
        }
        self::assertSame(' ', $destination->characters[0]);
        self::assertSame(0, $destination->foregrounds[0]);
        self::assertSame(' ', $destination->characters[2 * 5]);
        self::assertSame(0, $destination->foregrounds[2 * 5]);
    }

    #[Test]
    public function copyRowFromFirstColumnCopied(): void
    {
        $source = new Frame(5, 2);
        $source->putChar(0, 0, 'Z', 99);

        $destination = new Frame(5, 2);
        $destination->copyRowFrom($source, 0);

        self::assertSame('Z', $destination->characters[0]);
        self::assertSame(99, $destination->foregrounds[0]);
    }

    #[Test]
    public function copyRowFromLastColumnCopied(): void
    {
        $source = new Frame(5, 2);
        $source->putChar(4, 0, 'Z', 99);

        $destination = new Frame(5, 2);
        $destination->copyRowFrom($source, 0);

        self::assertSame('Z', $destination->characters[4]);
        self::assertSame(99, $destination->foregrounds[4]);
    }

    // ── Mutant-killing: overlay at height-1 must work, at height must not (L252) ──

    #[Test]
    public function overlayRowAtDestinationHeightMinusOneIsWritten(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 1);
        $source->putChar(0, 0, 'X', 1);
        $destination->overlay($source, 0, 2);
        self::assertSame('X', $destination->characters[2 * 5]);
        self::assertSame(1, $destination->foregrounds[2 * 5]);
    }

    #[Test]
    public function overlayRowAtDestinationHeightIsNotWritten(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 2);
        $source->putChar(0, 0, 'A', 1);
        $source->putChar(0, 1, 'B', 1);
        $destination->overlay($source, 0, 2);
        self::assertSame('A', $destination->characters[2 * 5]);
        for ($i = 0; $i < 2 * 5; ++$i) {
            self::assertSame(' ', $destination->characters[$i]);
        }
    }

    // ── Mutant-killing: fillRect max(0,x) - with x=0, max(0,0)=0 vs max(-1,0)=0 ──
    // Need test where x is positive but small, so max(0,x) != max(-1,x)

    #[Test]
    public function fillRectAtXZeroFillsFromColumnZero(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(0, 0, 2, 1, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function fillRectAtYZeroFillsFromRowZero(): void
    {
        $frame = new Frame(1, 5);
        $frame->fillRect(0, 0, 1, 2, 0, 0, 0, 'X');
        self::assertSame('X', $frame->characters[0]);
        self::assertSame('X', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    // ── Mutant-killing: fillRect max(0,-1) must not write to negative indices (L230-231) ──

    #[Test]
    public function fillRectNegativeXDoesNotWriteToNegativeIndices(): void
    {
        $frame = new Frame(5, 1);
        $frame->fillRect(-1, 0, 3, 1, 0, 0, 0, 'X');
        self::assertCount(5, $frame->characters, 'No extra entries should exist at negative indices');
        self::assertSame(range(0, 4), array_keys($frame->characters));
    }

    #[Test]
    public function fillRectNegativeYDoesNotWriteToNegativeIndices(): void
    {
        $frame = new Frame(1, 5);
        $frame->fillRect(0, -1, 1, 3, 0, 0, 0, 'X');
        self::assertCount(5, $frame->characters, 'No extra entries should exist at negative indices');
        self::assertSame(range(0, 4), array_keys($frame->characters));
    }

    // ── Mutant-killing: overlay at exact height would write out of bounds (L252) ──

    #[Test]
    public function overlayAtHeightDoesNotCreateOutOfBoundsEntries(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 4);
        $source->putChar(0, 3, 'X', 1);
        $destination->overlay($source, 0, 0);
        self::assertCount(15, $destination->characters, 'No extra entries should exist beyond buffer size');
    }

    #[Test]
    public function overlayNegativeYDoesNotCreateOutOfBoundsEntries(): void
    {
        $destination = new Frame(5, 3);
        $source = new Frame(1, 2);
        $source->putChar(0, 0, 'X', 1);
        $source->putChar(0, 1, 'Y', 1);
        $destination->overlay($source, 0, -1);
        self::assertCount(15, $destination->characters, 'No extra entries should exist');
        self::assertSame(range(0, 14), array_keys($destination->characters));
    }

    #[Test]
    public function putStringCombiningMarkKeptWithBase(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "cafe\u{0301}");
        self::assertSame('c', $frame->character(0));
        self::assertSame('a', $frame->character(1));
        self::assertSame('f', $frame->character(2));
        self::assertSame("e\u{0301}", $frame->character(3));
    }

    #[Test]
    public function putStringFlagOccupiesTwoCells(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, "\u{1F1FA}\u{1F1F8}");
        self::assertSame("\u{1F1FA}\u{1F1F8}", $frame->character(0));
        self::assertSame('', $frame->character(1));
    }

    #[Test]
    public function widthStoredForNormalCharacter(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'A');
        self::assertSame(1, $frame->width(0));
    }

    #[Test]
    public function widthStoredForWideCharacter(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, '中');
        self::assertSame(2, $frame->width(0));
        self::assertSame(0, $frame->width(1));
    }

    #[Test]
    public function widthResetOnClear(): void
    {
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, '中');
        $frame->clear();
        self::assertSame(1, $frame->width(0));
        self::assertSame(1, $frame->width(1));
    }

    #[Test]
    public function putCharRejectedOutsideClipTopLeft(): void
    {
        // L93 clipRejects: x < clip.x — cells left of clip rejected
        $frame = new Frame(10, 5);
        $frame->pushClip(new Rect(3, 2, 4, 2));

        $frame->putChar(2, 2, 'X'); // x=2 < clip.x=3 → rejected
        $frame->putChar(3, 1, 'Y'); // y=1 < clip.y=2 → rejected

        self::assertSame(' ', $frame->characters[2 * 10 + 2], 'Cell left of clip must not be written');
        self::assertSame(' ', $frame->characters[1 * 10 + 3], 'Cell above clip must not be written');
    }

    #[Test]
    public function putCharRejectedOutsideClipBottomRight(): void
    {
        // L93 clipRejects: x >= clip.right() and y >= clip.bottom() — cells right/below rejected
        $frame = new Frame(10, 5);
        $frame->pushClip(new Rect(3, 2, 4, 2)); // covers (3..6, 2..3)

        $frame->putChar(7, 2, 'X'); // x=7 >= right()=7 → rejected
        $frame->putChar(3, 4, 'Y'); // y=4 >= bottom()=4 → rejected

        self::assertSame(' ', $frame->characters[2 * 10 + 7], 'Cell at x == clip.right() must not be written');
        self::assertSame(' ', $frame->characters[4 * 10 + 3], 'Cell at y == clip.bottom() must not be written');
    }

    #[Test]
    public function putCharAcceptedInsideClip(): void
    {
        // Verify clipRejects doesn't reject inside the clip area
        $frame = new Frame(10, 5);
        $frame->pushClip(new Rect(3, 2, 4, 2));

        $frame->putChar(3, 2, 'A'); // top-left of clip
        $frame->putChar(6, 3, 'B'); // bottom-right inside clip (right()-1 = 6, bottom()-1 = 3)

        self::assertSame('A', $frame->characters[2 * 10 + 3]);
        self::assertSame('B', $frame->characters[3 * 10 + 6]);
    }

    #[Test]
    public function putStringRejectsRowOutsideClipYRange(): void
    {
        // L139/L140: putString must reject and return 0 when y is outside clip's y range.
        $frame = new Frame(10, 5);
        $frame->pushClip(new Rect(0, 2, 10, 2));

        $consumedAbove = $frame->putString(0, 0, 'hello'); // y=0 < clip.y=2
        $consumedBelow = $frame->putString(0, 4, 'world'); // y=4 >= clip.bottom()=4

        self::assertSame(0, $consumedAbove, 'putString must return 0 when y is above clip');
        self::assertSame(0, $consumedBelow, 'putString must return 0 when y is at/below clip.bottom()');
        // No cells should be written
        for ($i = 0; $i < 50; ++$i) {
            self::assertSame(' ', $frame->characters[$i], "Cell {$i} must remain unchanged");
        }
    }

    #[Test]
    public function putStringWritesInsideClipWithNonAscii(): void
    {
        // L171 LogicalAnd: clipRejects check inside putString slow-path foreach.
        // Use a non-ASCII char to force the slow path (fast path bails on clipStack).
        $frame = new Frame(10, 5);
        $frame->pushClip(new Rect(2, 1, 4, 2)); // (2..5, 1..2)

        // 'cát' — 'á' is non-ASCII, forces slow path. Render at (1, 1) — first char is outside clip.
        $frame->putString(1, 1, 'café');

        // x=1 (outside clip) → 'c' rejected. x=2 → 'a' accepted. x=3 → 'f' accepted. x=4 → 'é' accepted. x=5 outside (>= right=6 is false, so still inside).
        // Wait clip.right() = 2+4 = 6. So x=5 is < 6 → accepted.
        self::assertSame(' ', $frame->characters[1 * 10 + 1], "x=1 outside clip — must reject 'c'");
        self::assertSame('a', $frame->characters[1 * 10 + 2], "x=2 inside clip — 'a' accepted");
        self::assertSame('f', $frame->characters[1 * 10 + 3]);
        self::assertSame('é', $frame->characters[1 * 10 + 4]);
    }

    #[Test]
    public function putStringWithWideCharRejectsWhenContinuationOutsideClip(): void
    {
        // L179: wide-char (width=2) — the continuation cell (col+1) must also be inside the clip.
        $frame = new Frame(10, 5);
        // Pre-fill clipped cells so we can detect what was written
        for ($i = 0; $i < 50; ++$i) {
            $frame->putChar($i % 10, intdiv($i, 10), '.');
        }
        $frame->pushClip(new Rect(2, 1, 4, 2)); // covers cols 2..5

        // '中' is width 2. Place at col 5 — lead at 5 (inside clip), continuation at col 6 (outside).
        $frame->putString(5, 1, '中');

        self::assertSame('.', $frame->characters[1 * 10 + 5], 'Wide-char lead must not write when its continuation is outside the clip');
        self::assertSame(1, $frame->widths[1 * 10 + 5], 'No width-2 lead may be left without a continuation');
        self::assertSame('.', $frame->characters[1 * 10 + 6], 'Wide-char continuation must not write when outside clip');
    }

    #[Test]
    public function putStringWithWideCharWritesContinuationInsideClip(): void
    {
        // L179 IncrementInteger: `clipRejects(column + 1, y)` → `clipRejects(column + 2, y)`.
        // When col+1 is the last cell INSIDE the clip and col+2 is OUTSIDE, current writes; mutation skips.
        $frame = new Frame(10, 5);
        for ($i = 0; $i < 50; ++$i) {
            $frame->putChar($i % 10, intdiv($i, 10), '.');
        }
        // Clip: cols 0..6 (right = 7, so valid cols 0..6). Wide char at col=5: col+1=6 (inside), col+2=7 (outside).
        $frame->pushClip(new Rect(0, 0, 7, 5));

        $frame->putString(5, 1, '中');

        self::assertSame('中', $frame->characters[1 * 10 + 5], 'Wide-char primary at col 5 must write');
        self::assertSame('', $frame->characters[1 * 10 + 6], 'Wide-char continuation at col 6 (inside clip) must be the empty continuation marker');
    }

    #[Test]
    public function putAnsiWithWideCharWritesContinuationInsideClip(): void
    {
        // L325 mutants: same as L179 but in putAnsi path.
        $frame = new Frame(10, 5);
        for ($i = 0; $i < 50; ++$i) {
            $frame->putChar($i % 10, intdiv($i, 10), '.');
        }
        $frame->pushClip(new Rect(0, 0, 7, 5));

        $frame->putAnsi(5, 1, '中');

        self::assertSame('中', $frame->characters[1 * 10 + 5], 'putAnsi wide-char primary must write');
        self::assertSame('', $frame->characters[1 * 10 + 6], 'putAnsi wide-char continuation must be empty marker when inside clip');
    }

    #[Test]
    public function putAnsiWithWideCharRejectsTheWholeCharWhenContinuationOutsideClip(): void
    {
        // L325 IncrementInteger: column + 1 → column + 2. With column+2 outside clip, the char writes (wrongly).
        $frame = new Frame(10, 5);
        for ($i = 0; $i < 50; ++$i) {
            $frame->putChar($i % 10, intdiv($i, 10), '.');
        }
        $frame->pushClip(new Rect(2, 1, 4, 2)); // cols 2..5

        $frame->putAnsi(5, 1, '中');

        self::assertSame('.', $frame->characters[1 * 10 + 5], 'putAnsi must drop the lead when its continuation is outside the clip');
        self::assertSame(1, $frame->widths[1 * 10 + 5], 'No width-2 lead may be left without a continuation');
        self::assertSame('.', $frame->characters[1 * 10 + 6], 'putAnsi continuation outside clip must not write');
    }

    #[Test]
    public function clearResetsAFrameThatIsBlankExceptAStaleForeground(): void
    {
        $frame = new Frame(4, 2);
        $frame->foregrounds[0] = Color::rgb(255, 0, 0)->value;
        $frame->markRowDirty(0);

        $frame->clear();

        self::assertSame(0, $frame->foregrounds[0], 'A lone stale foreground must defeat the already-blank shortcut and be cleared');
    }

    #[Test]
    public function clearResetsAFrameThatIsBlankExceptAStaleAttribute(): void
    {
        $frame = new Frame(4, 2);
        $frame->attributes[0] = Attr::UNDERLINE;
        $frame->markRowDirty(0);

        $frame->clear();

        self::assertSame(0, $frame->attributes[0], 'A lone stale attribute must defeat the already-blank shortcut and be cleared');
    }

    #[Test]
    public function clearResetsACellWhereEveryAspectIsNonBlank(): void
    {
        $frame = new Frame(4, 2);
        $frame->putStyled(0, 0, '中', new Style(
            foreground: Color::rgb(255, 0, 0),
            background: Color::rgb(0, 0, 255),
            attributes: Attr::BOLD,
        ));

        $frame->clear();

        self::assertSame(' ', $frame->characters[0], 'A cell dirty in all five aspects (char, fg, bg, attr, width) must still be cleared');
        self::assertSame(1, $frame->widths[0]);
        self::assertTrue($frame->isRowDirty(0), 'Erasing previous content must mark the row dirty');
    }

    #[Test]
    public function clearResetsAFrameThatIsBlankExceptAStaleWidth(): void
    {
        $frame = new Frame(4, 2);
        $frame->widths[0] = 2;
        $frame->markRowDirty(0);

        $frame->clear();

        self::assertSame(1, $frame->widths[0], 'A lone stale cell width must defeat the already-blank shortcut and be cleared');
    }

    #[Test]
    public function everyWriterDropsAWideCharWhoseContinuationFallsOutsideTheClip(): void
    {
        foreach (['putChar', 'putString', 'putAnsi'] as $writer) {
            $frame = new Frame(10, 1);
            for ($column = 0; $column < 10; ++$column) {
                $frame->putChar($column, 0, '.');
            }
            $frame->pushClip(new Rect(2, 0, 4, 1));

            match ($writer) {
                'putChar' => $frame->putChar(5, 0, '中'),
                'putString' => $frame->putString(5, 0, '中'),
                'putAnsi' => $frame->putAnsi(5, 0, '中'),
            };

            self::assertSame('.', $frame->characters[5], "{$writer} must drop the whole wide char, not leave a lead without a continuation");
            self::assertSame(1, $frame->widths[5], "{$writer} must not leave width 2 on a cell whose continuation was clipped");
            self::assertSame('.', $frame->characters[6], "{$writer} must not write the continuation outside the clip");
        }
    }

    #[Test]
    public function putAnsiComposesACombiningMarkIntoThePrecedingCell(): void
    {
        $frame = new Frame(5, 1);

        $frame->putAnsi(0, 0, "e\u{0301}f");

        self::assertSame("e\u{0301}", $frame->characters[0], 'A combining mark must compose with the cell before it, as putString does');
        self::assertSame('f', $frame->characters[1], 'A zero-width mark must not consume a column');
    }

    #[Test]
    public function overlayDropsAWideLeadWithNoRoomForItsContinuation(): void
    {
        $source = new Frame(2, 1);
        $source->putChar(0, 0, '中');
        $destination = new Frame(5, 1);

        $destination->overlay($source, 4, 0);

        self::assertSame(' ', $destination->characters[4], 'A wide lead must not land in the last column, where its continuation cannot follow');
        self::assertSame(1, $destination->widths[4], 'No width-2 lead may be left without a continuation');
    }

    #[Test]
    public function putStringLeavesTheRowCleanWhenEveryCellWasClipped(): void
    {
        $frame = new Frame(10, 5);
        $frame->pushClip(new Rect(5, 0, 5, 5));

        $frame->putString(0, 0, 'ábc');

        self::assertFalse($frame->isRowDirty(0), 'A row where every cell was clip-rejected must not be dirty');
    }

    #[Test]
    public function aForcedFullRepaintSurvivesTheNextClear(): void
    {
        $frame = new Frame(4, 3);

        $frame->markAllDirty();
        $frame->clear();

        for ($row = 0; $row < 3; ++$row) {
            self::assertTrue($frame->isRowDirty($row), "Row {$row} was forced dirty, so clear() must keep it dirty");
        }
    }
}
