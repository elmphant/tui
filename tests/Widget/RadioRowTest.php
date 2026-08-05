<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\RadioRow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RadioRowTest extends TestCase
{
    #[Test]
    public function marksTheSelectedOptionAlongOneRow(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all'])->withSelected(1);

        self::assertSame('○ system  ● pages  ○ all', trim($this->render($row)));
    }

    #[Test]
    public function breaksOntoAFurtherRowRatherThanRunningOffTheEdge(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all'])->withSelected(1);

        $frame = new Frame(10, 3);
        $row->render($frame, new Rect(0, 0, 10, 3), new RenderContext(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame('○ system', trim($screen->getLine(0)));
        self::assertSame('● pages', trim($screen->getLine(1)), 'the mark stays with the value it belongs to');
        self::assertSame('○ all', trim($screen->getLine(2)));
    }

    #[Test]
    public function theArrowsWalkTheOptionsAndSpacePicksTheOneTheyRestOn(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $row = RadioRow::new(['system', 'pages', 'all'])->withFocus(true);

        $walked = $row->update(new KeyMsg(Key::Right), $area)->model;
        self::assertInstanceOf(RadioRow::class, $walked);
        self::assertSame(-1, $walked->selected(), 'walking alone picks nothing');

        $picked = $walked->update(new KeyMsg(' '), $area)->model;
        self::assertInstanceOf(RadioRow::class, $picked);
        self::assertSame(1, $picked->selected());
    }

    #[Test]
    public function aClickPicksTheOptionUnderIt(): void
    {
        $row = RadioRow::new(['system', 'pages', 'all']);
        $area = new Rect(0, 0, 40, 1);

        $picked = $row->update(new MouseMsg(12, 0, MouseAction::Press, MouseButton::Left), $area)->model;

        self::assertInstanceOf(RadioRow::class, $picked);
        self::assertSame(1, $picked->selected(), 'the second option sits under that column');
    }

    private function render(RadioRow $row): string
    {
        $frame = new Frame(40, 1);
        $row->render($frame, new Rect(0, 0, 40, 1), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame)->getLine(0);
    }
}
