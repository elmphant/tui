<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Application\ChordListApp;
use Elmphant\Tui\Widget\Listing\ListView;
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

    #[Test]
    public function theFirstKeyOfAChordWaitsForTheNext(): void
    {
        $terminal = new VirtualTerminal(30, 8);
        $clock = new TestClock(1000.0);
        $program = Program::new(new ChordListApp(self::listBindingG()))->withTerminal($terminal)->withClock($clock);

        EventLoop::defer(static function () use ($terminal, $clock, $program): void {
            $terminal->simulateInput('g');
            $clock->advance(0.4);
            $program->tick();
            $terminal->closeInput();
            $program->tick();
        });

        self::assertSame(0, $program->start()->list->cursor());
    }

    #[Test]
    public function aFirstKeyNothingFollowsReachesTheListWhenTheChordTimesOut(): void
    {
        $terminal = new VirtualTerminal(30, 8);
        $program = Program::new(new ChordListApp(self::listBindingG()))->withTerminal($terminal);
        $drawnUnasked = '';

        EventLoop::defer(static function () use ($terminal, $program, &$drawnUnasked): void {
            $terminal->simulateInput('g');
            $program->tick();
            $terminal->clearOutput();
            EventLoop::delay(0.8, static function () use ($terminal, $program, &$drawnUnasked): void {
                $drawnUnasked = $terminal->output();
                $terminal->closeInput();
                $program->tick();
            });
        });

        self::assertSame(1, $program->start()->list->cursor());
        self::assertNotSame('', $drawnUnasked, 'the program did not wake up when the chord timed out');
    }

    private static function listBindingG(): ListView
    {
        return ListView::fromStrings(['one', 'two', 'three'])->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['g']]));
    }
}
