<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\QuitMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BatchCmdTest extends TestCase
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

        self::assertInstanceOf(Cmd\BatchCmd::class, $batch);
        self::assertSame([$first, $second, $third], $batch->commands);
    }

    /**
     * A wrap belongs to what is inside it, not to whatever it is collected beside.
     * Lifting its children out would send them off unwrapped.
     */
    #[Test]
    public function aWrappedBatchKeepsItsShape(): void
    {
        $wrapped = Cmd::batch(Cmd::quit(), Cmd::focusNext())
            ->map(static fn (Message $message): Message => $message);

        $beside = Cmd::focusPrevious();

        $batch = Cmd::batch($wrapped, $beside);

        self::assertInstanceOf(Cmd\BatchCmd::class, $batch);
        self::assertSame([$wrapped, $beside], $batch->commands);
    }
}
