<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Runtime\FullScreenSurface;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FullScreenSurfaceTest extends TestCase
{
    #[Test]
    public function leavingPutsTheShellOnTheLineBelowTheLastRowOfTheTerminalAsItIsNow(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $surface = new FullScreenSurface($terminal, new Screen($terminal));
        $terminal->resizeTo(80, 7);

        self::assertSame(Ansi::cursorToRowStart(6) . "\n", $surface->leave());
    }

    #[Test]
    public function aTerminalWithNoRowsIsLeftFromTheFirstRow(): void
    {
        $terminal = new VirtualTerminal(80, 0);

        self::assertSame(Ansi::cursorToRowStart(0) . "\n", (new FullScreenSurface($terminal, new Screen($terminal)))->leave());
    }
}
