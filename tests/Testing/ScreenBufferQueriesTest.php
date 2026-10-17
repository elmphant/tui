<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Testing\ScreenBuffer;
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
    public function aCellSaysHowItIsStyled(): void
    {
        $frame = new Frame(10, 1);
        $frame->putStyled(2, 0, 'x', new Style(Color::hex('FF8700'), Color::ansi(0), Attr::BOLD));

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertEquals(new Style(Color::hex('FF8700'), Color::ansi(0), Attr::BOLD), $screen->styleAt(0, 2));
        self::assertEquals(new Style(), $screen->styleAt(0, 0));
    }

    #[Test]
    public function capturedOutputKeepsTheStyleItWasWrittenIn(): void
    {
        $screen = new ScreenBuffer("\e[1;31mred\e[0m plain", 20, 1);

        self::assertEquals(new Style(Color::ansi(1), null, Attr::BOLD), $screen->styleAt(0, 0));
        self::assertEquals(new Style(), $screen->styleAt(0, 4));
    }
}
