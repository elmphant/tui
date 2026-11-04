<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Testing\ScreenBuffer;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenBufferQueriesTest extends TestCase
{
    #[Test]
    public function textIsFoundAtTheRowAndColumnItIsDrawnAt(): void
    {
        $frame = new Frame(20, 3);
        $frame->putString(4, 1, 'Run now');

        self::assertSame(['row' => 1, 'column' => 4], ScreenBuffer::fromFrame($frame)->find('Run'));
        self::assertSame(1, ScreenBuffer::fromFrame($frame)->rowOf('now'));
    }

    #[Test]
    public function aLaterMatchIsFoundByItsPlace(): void
    {
        $frame = new Frame(20, 3);
        $frame->putString(0, 0, 'tab tab');
        $frame->putString(2, 2, 'tab');

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame(['row' => 0, 'column' => 4], $screen->find('tab', 1));
        self::assertSame(['row' => 2, 'column' => 2], $screen->find('tab', 2));
        self::assertNull($screen->find('tab', 3));
    }

    #[Test]
    public function columnsCountTheCellsAWideCharacterTakes(): void
    {
        $frame = new Frame(20, 1);
        $frame->putString(0, 0, '日本 ok');

        self::assertSame(['row' => 0, 'column' => 5], ScreenBuffer::fromFrame($frame)->find('ok'));
    }

    #[Test]
    public function textThatIsNotOnScreenIsNowhere(): void
    {
        self::assertNull(ScreenBuffer::fromFrame(new Frame(10, 2))->find('Run'));
        self::assertNull(ScreenBuffer::fromFrame(new Frame(10, 2))->rowOf('Run'));
    }

    #[Test]
    public function aSearchStaysInsideTheAreaItIsGiven(): void
    {
        $frame = new Frame(20, 3);
        $frame->putString(0, 0, 'Run');
        $frame->putString(12, 1, 'Run');

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame(['row' => 1, 'column' => 12], $screen->find('Run', within: new Rect(10, 0, 10, 3)));
        self::assertSame(1, $screen->rowOf('Run', within: new Rect(10, 0, 10, 3)));
        self::assertNull($screen->find('Run', within: new Rect(0, 2, 20, 1)));
        self::assertNull($screen->find('Run', within: new Rect(13, 1, 7, 1)), 'a match must start inside the area');
    }

    #[Test]
    public function locatingTextThatIsNotThereSaysWhatWasLookedFor(): void
    {
        $frame = new Frame(20, 2);
        $frame->putString(4, 1, 'Run');
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame(['row' => 1, 'column' => 4], $screen->locate('Run'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"Stop" is not on screen');
        $screen->locate('Stop');
    }

    #[Test]
    public function aCellSaysHowItIsStyled(): void
    {
        $frame = new Frame(10, 1);
        $frame->putStyled(2, 0, 'x', new Style(Color::hex('FF8700'), Color::ansi(AnsiColor::Black), Attr::BOLD));

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertEquals(new Style(Color::hex('FF8700'), Color::ansi(AnsiColor::Black), Attr::BOLD), $screen->styleAt(2, 0));
        self::assertEquals(new Style(), $screen->styleAt(0, 0));
    }

    #[Test]
    public function aCellIsAddressedByColumnThenRowAsTheFrameWroteIt(): void
    {
        $frame = new Frame(5, 4);
        $frame->putStyled(3, 1, 'x', new Style(Color::ansi(AnsiColor::Green)));

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame('x', $screen->textAt(3, 1, 1));
        self::assertEquals(new Style(Color::ansi(AnsiColor::Green)), $screen->styleAt(3, 1));
    }

    #[Test]
    public function capturedOutputKeepsTheStyleItWasWrittenIn(): void
    {
        $screen = new ScreenBuffer("\e[1;31mred\e[0m plain", 20, 1);

        self::assertEquals(new Style(Color::ansi(AnsiColor::Red), null, Attr::BOLD), $screen->styleAt(0, 0));
        self::assertEquals(new Style(), $screen->styleAt(4, 0));
    }
}
