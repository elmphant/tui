<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Application\ChordListApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramChordTest extends TestCase
{
    #[Test]
    public function aChordTypedInARunningProgramMovesTheListToItsFirstRow(): void
    {
        $terminal = new VirtualTerminal(30, 8);
        $clock = new TestClock(1000.0);
        $program = Program::new(new ChordListApp())->withTerminal($terminal)->withClock($clock);

        EventLoop::defer(static function () use ($terminal, $clock, $program): void {
            $clock->advance(1);
            $program->tick();
            $terminal->simulateInput("\x1b[B\x1b[Bgg");
            $clock->advance(0.1);
            $program->tick();
            $terminal->simulateInput("\x11");
        });

        self::assertSame(0, $program->start()->list->cursor());
    }
}
