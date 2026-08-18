<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\BatchCmd;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WrappedBatchKeepsItsShapeTest extends TestCase
{
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

        self::assertInstanceOf(BatchCmd::class, $batch);
        self::assertSame([$wrapped, $beside], $batch->commands);
    }
}
