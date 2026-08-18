<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\BatchCmd;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\QuitMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BatchFlattensTest extends TestCase
{
    /**
     * A batch is a list of effects, and one built by adding to another is the same
     * list. Nesting only makes every reader walk a shape that means nothing.
     */
    #[Test]
    public function aBatchOfBatchesIsOneList(): void
    {
        $first = Cmd::message(QuitMsg::Quit);
        $second = Cmd::quit();
        $third = Cmd::focusNext();

        $batch = Cmd::batch(Cmd::batch($first, $second), $third);

        self::assertInstanceOf(BatchCmd::class, $batch);
        self::assertSame([$first, $second, $third], $batch->commands);
    }
}
