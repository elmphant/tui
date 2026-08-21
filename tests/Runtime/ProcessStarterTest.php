<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Runtime\ProcessStarter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spawning the child process a command asked for, and saying so by throwing
 * where it cannot.
 */
final class ProcessStarterTest extends TestCase
{
    #[Test]
    public function itStartsTheProcessACommandNamed(): void
    {
        $running = (new ProcessStarter())->exec(Cmd::exec(['echo', 'hello']), null);

        self::assertTrue($running->isRunning());
    }

    #[Test]
    public function itStartsAStreamingProcessTheSameWay(): void
    {
        $running = (new ProcessStarter())->streaming(Cmd::process(['echo', 'hello']), null);

        self::assertTrue($running->isRunning());
    }
}
