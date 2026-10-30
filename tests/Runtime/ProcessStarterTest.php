<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Runtime\ProcessStarter;
use Elmphant\Tui\Testing\TestClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessStarterTest extends TestCase
{
    #[Test]
    public function itStartsTheProcessACommandNamed(): void
    {
        $running = (new ProcessStarter())->exec(Cmd::exec(['echo', 'hello']), null);

        self::assertTrue($running->isRunning());
    }

    #[Test]
    public function aCommandWithoutATimeoutRunsForAsLongAsItTakes(): void
    {
        self::assertNull(ProcessStarter::processFor(['sleep', '1'], null, null, [], null)->getTimeout());
    }

    #[Test]
    public function aCommandWithATimeoutIsHeldToIt(): void
    {
        self::assertSame(2.5, ProcessStarter::processFor(['sleep', '1'], null, 2.5, [], null)->getTimeout());
    }

    #[Test]
    public function itStartsAStreamingProcessTheSameWay(): void
    {
        $running = (new ProcessStarter())->streaming(Cmd::process(['echo', 'hello']), null);

        self::assertTrue($running->isRunning());
    }

    #[Test]
    public function aStreamAskedForLinesHandsOverALineWrittenInTwoGoesWhole(): void
    {
        $script = 'echo "on"; flush(); usleep(150000); echo "e\n";';
        $running = (new ProcessStarter())->streaming(Cmd::process([PHP_BINARY, '-r', $script])->withLineFraming(), null);

        $outputs = [];
        $deadline = microtime(true) + 5.0;
        while ($running->isRunning() && microtime(true) < $deadline) {
            foreach ($running->pump() as $message) {
                if ($message instanceof ProcessOutputMsg) {
                    $outputs[] = $message->output;
                }
            }
            usleep(10_000);
        }

        self::assertSame(["one\n"], $outputs);
    }

    #[Test]
    public function aFinishedProcessSaysWhenItStartedAndWhenItEnded(): void
    {
        $clock = new TestClock(100.0);
        $running = (new ProcessStarter($clock))->streaming(Cmd::process(['true']), null);
        $clock->advance(2.5);

        $finished = null;
        $deadline = microtime(true) + 5.0;
        while ($finished === null && microtime(true) < $deadline) {
            foreach ($running->pump() as $message) {
                $finished = $message instanceof ProcessFinishedMsg ? $message : $finished;
            }
            usleep(10_000);
        }

        self::assertNotNull($finished);
        self::assertSame(100.0, $finished->startedAt);
        self::assertSame(102.5, $finished->finishedAt);
    }
}
