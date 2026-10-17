<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Testing\Issued;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssuedTest extends TestCase
{
    #[Test]
    public function aCommandIsFoundInsideBatchesMapsAndScopes(): void
    {
        $exec = Cmd::exec(['ls']);
        $tick = Cmd::tick(1.0);
        $command = Cmd::batch(
            Cmd::batch($exec)->map(static fn (Message $message): Message => $message)->scopedTo('child'),
            $tick,
        );

        self::assertSame([$exec], Issued::in($command, ExecCmd::class));
        self::assertSame([$tick], Issued::in($command, TickCmd::class));
        self::assertSame([$exec, $tick], Issued::in($command));
    }

    #[Test]
    public function noCommandHoldsNothing(): void
    {
        self::assertSame([], Issued::in(null));
    }
}
