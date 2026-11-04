<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\ScriptedModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramTerminalHandBackTest extends TestCase
{
    #[Test]
    public function whileItRunsTheTerminalTakesPastesAndTheModelFirstHearsItsSize(): void
    {
        $terminal = new VirtualTerminal(80, 30);
        $seen = new ArrayObject();

        Program::new(new ScriptedModel(
            render: static function (): void {
            },
            onMessage: static function (Message $message) use ($seen, $terminal): Cmd {
                $seen->append([$message, $terminal->isBracketedPasteEnabled()]);

                return Cmd::quit();
            },
        ))->withTerminal($terminal)->start();

        self::assertEquals([new ResizeMsg(80, 30), true], $seen[0]);
    }

    #[Test]
    public function aFinishedProgramHandsTheTerminalBackTheWayItFoundIt(): void
    {
        $terminal = new VirtualTerminal(80, 30);

        Program::new(self::quitting())->withTerminal($terminal)->start();

        self::assertFalse($terminal->isRawModeEnabled());
        self::assertTrue($terminal->isCursorVisible());
        self::assertFalse($terminal->isBracketedPasteEnabled());
        self::assertStringContainsString(Ansi::RESET_STYLE, $terminal->output());
        self::assertStringEndsWith(Ansi::cursorToRowStart(29) . "\n", $terminal->output(), 'the shell goes on below the last row');
    }

    #[Test]
    public function aFinishedProgramOnTheAlternateScreenLeavesTheCursorWhereTheShellHadIt(): void
    {
        $terminal = new VirtualTerminal(80, 30);

        Program::new(self::quitting())->withTerminal($terminal)->withScreenMode(ScreenMode::alternate())->start();

        self::assertFalse($terminal->isAltScreenEnabled());
        self::assertStringNotContainsString(Ansi::cursorToRowStart(29), $terminal->output());
    }

    #[Test]
    public function aFinishedProgramLeavesNothingOfItsOwnOnTheEventLoop(): void
    {
        $before = EventLoop::getIdentifiers();

        Program::new(self::quitting())->withTerminal(new VirtualTerminal(80, 30))->start();

        self::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $before)));
    }

    private static function quitting(): ScriptedModel
    {
        return new ScriptedModel(render: static function (): void {
        }, atStart: Cmd::quit());
    }
}
