<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class AFlashInARunningProgramTest extends TestCase
{
    #[Test]
    public function aRunningProgramLightsAFlashedRowAndWakesToTakeItAway(): void
    {
        $terminal = new VirtualTerminal(30, 6);
        $program = Program::new(new FlashOnStartApp())->withTerminal($terminal);
        $backgrounds = [];

        EventLoop::defer(static function () use ($terminal, $program, &$backgrounds): void {
            $program->tick();
            EventLoop::delay(0.05, static function () use ($terminal, &$backgrounds): void {
                $backgrounds[] = self::backgroundOfDeploy($terminal);
            });
            EventLoop::delay(0.5, static function () use ($terminal, $program, &$backgrounds): void {
                $backgrounds[] = self::backgroundOfDeploy($terminal);
                $terminal->closeInput();
                $program->tick();
            });
        });
        $program->start();

        self::assertNotSame(0, $backgrounds[0], 'lit while the flash runs');
        self::assertSame(0, $backgrounds[1], 'plain once it is over, with no input to wake the program');
    }

    private static function backgroundOfDeploy(VirtualTerminal $terminal): int
    {
        $screen = new ScreenBuffer($terminal->output(), 30, 6);
        ['row' => $row, 'column' => $column] = $screen->findOrFail('deploy');

        return $screen->styleAt($column, $row)->background->value ?? 0;
    }
}

final readonly class FlashOnStartApp extends App
{
    public Table $jobs;

    public function __construct(?Table $jobs = null)
    {
        $this->jobs = $jobs ?? Table::new([Column::new('Job', Constraint::flex())], [Row::new('build'), Row::new('deploy')]);
    }

    public function view(): Widget
    {
        return $this->bind('jobs');
    }

    protected function setup(): ?Cmd
    {
        return Cmd::flash('jobs', 'deploy', 0.2);
    }
}
