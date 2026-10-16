<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Choice\RadioRow;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RadioRowTest extends TestCase
{
    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['←/→' => 'move', 'Enter' => 'choose'],
            RadioRow::new([])->hints(),
        );
    }

    #[Test]
    public function optionsThatReadAlikeAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RadioRow options must read differently; "a" is at 0 and 2.');

        RadioRow::new(['a', 'b', 'a']);
    }

    #[Test]
    public function reloadedOptionsThatReadAlikeAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RadioRow options must read differently; "a" is at 0 and 2.');

        RadioRow::new(['a', 'b'])->withOptions(['a', 'b', 'a']);
    }

    #[Test]
    public function marksTheSelectedOptionAlongOneRow(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all'])->withSelected(1);

        self::assertSame(
            RadioRow::UNSELECTED_MARK . 'system  ' . RadioRow::SELECTED_MARK . 'pages  ' . RadioRow::UNSELECTED_MARK . 'all',
            trim($this->render($row)),
        );
    }

    #[Test]
    public function breaksOntoAFurtherRowRatherThanRunningOffTheEdge(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all'])->withSelected(1);

        $frame = new Frame(10, 3);
        $row->render($frame, new Rect(0, 0, 10, 3), new RenderContext(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame(RadioRow::UNSELECTED_MARK . 'system', trim($screen->lineAt(0)));
        self::assertSame(RadioRow::SELECTED_MARK . 'pages', trim($screen->lineAt(1)), 'the mark stays with the value it belongs to');
        self::assertSame(RadioRow::UNSELECTED_MARK . 'all', trim($screen->lineAt(2)));
    }

    #[Test]
    public function theArrowsWalkTheOptionsAndSpacePicksTheOneTheyRestOn(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $row = RadioRow::new(['system', 'pages', 'all']);

        $walked = $row->update(new KeyMsg(Key::Right), $area)->model;
        self::assertInstanceOf(RadioRow::class, $walked);
        self::assertSame(-1, $walked->selectedIndex(), 'walking alone picks nothing');

        $picked = $walked->update(new KeyMsg(' '), $area)->model;
        self::assertInstanceOf(RadioRow::class, $picked);
        self::assertSame(1, $picked->selectedIndex());
    }

    #[Test]
    public function aRowToldNotToWrapRestsAtEitherEndInstead(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $row = RadioRow::new(['webp', 'avif', 'jxl'])->withWrapping(false);

        $left = $row->update(new KeyMsg(Key::Left), $area)->model;
        self::assertInstanceOf(RadioRow::class, $left);
        self::assertSame(0, $left->cursor(), 'the near end holds');

        $right = $row->withCursor(2)->update(new KeyMsg(Key::Right), $area)->model;
        self::assertInstanceOf(RadioRow::class, $right);
        self::assertSame(2, $right->cursor(), 'and so does the far one');
    }

    #[Test]
    public function aClearableRowLetsThePickedOptionBePutBackDown(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $row = RadioRow::new(['webp', 'avif', 'jxl'])->withClearable()->withSelected(0);

        $cleared = $row->update(new KeyMsg(' '), $area)->model;

        self::assertInstanceOf(RadioRow::class, $cleared);
        self::assertSame(-1, $cleared->selectedIndex(), 'pressing the one already picked puts it back down');
        self::assertNull($cleared->selectedValue());
    }

    #[Test]
    public function clickingTheOptionAClearableRowHoldsPutsItBackDown(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $row = RadioRow::new(['webp', 'avif', 'jxl'])->withClearable()->withSelected(0);

        $cleared = $row->update(
            new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left),
            $area,
        )->model;

        self::assertInstanceOf(RadioRow::class, $cleared);
        self::assertSame(-1, $cleared->selectedIndex());
    }

    #[Test]
    public function namesTheOptionItHasPickedOrNothingWhileNoneIsPicked(): void
    {
        $row = RadioRow::new(['system', 'pages']);

        self::assertNull($row->selectedValue());
        self::assertSame('pages', $row->withSelected(1)->selectedValue());
    }

    #[Test]
    public function asksForTheRowsItsOptionsNeedAtTheWidthItIsGiven(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all']);

        self::assertSame(1, $row->measureHeight(40));
        self::assertSame(3, $row->measureHeight(10), 'a narrow pane must hand it the rows it wraps onto');
        self::assertSame(1, $row->sizing()->rows->min, 'one row is the floor, the measurement raises it');
    }

    #[Test]
    public function showsWhereTheCursorRestsWhileItIsFocused(): void
    {
        $theme = Theme::dark();
        $row = RadioRow::new(['system', 'pages']);
        $frame = new Frame(40, 1);

        $row->render($frame, new Rect(0, 0, 40, 1), RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $frame->foregrounds[0], 'the arrows would otherwise walk an invisible cursor');
    }

    #[Test]
    public function aClickPicksTheOptionUnderIt(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all']);
        $area = new Rect(0, 0, 40, 1);

        $picked = $row->update(new MouseMsg(12, 0, MouseAction::Press, MouseButton::Left), $area)->model;

        self::assertInstanceOf(RadioRow::class, $picked);
        self::assertSame(1, $picked->selectedIndex(), 'the second option sits under that column');
    }

    private function render(RadioRow $row): string
    {
        $frame = new Frame(40, 1);
        $row->render($frame, new Rect(0, 0, 40, 1), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame)->lineAt(0);
    }
}
