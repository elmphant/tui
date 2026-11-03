<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\CmdLeaf;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CmdLeafTest extends TestCase
{
    #[Test]
    public function eachCommandInsideCarriesTheMapsAndScopesAroundItInTheOrderTheyRun(): void
    {
        $command = Cmd::batch(
            Cmd::message(new TestMessage('said'))
                ->map(static fn (Message $message): Message => new TestMessage('inner(' . self::named($message) . ')'))
                ->scopedTo('field'),
            Cmd::quit(),
        )->map(static fn (Message $message): Message => new TestMessage('outer(' . self::named($message) . ')'))->scopedTo('form');

        $leaves = CmdLeaf::all($command);

        self::assertSame([MessageCmd::class, QuitCmd::class], array_map(static fn (CmdLeaf $leaf): string => $leaf->command::class, $leaves));
        self::assertSame(['form/field', 'form'], array_map(static fn (CmdLeaf $leaf): ?string => $leaf->scope, $leaves));
        self::assertEquals(new TestMessage('outer(inner(said))'), ($leaves[0]->wrap ?? self::fail('the message is mapped'))(new TestMessage('said')));
        self::assertEquals(new TestMessage('outer(x)'), ($leaves[1]->wrap ?? self::fail('the quit is mapped'))(new TestMessage('x')));
    }

    #[Test]
    public function aBareCommandIsItsOwnLeafAndNothingHasNone(): void
    {
        $quit = Cmd::quit();

        self::assertEquals([[$quit, null, null]], array_map(static fn (CmdLeaf $leaf): array => [$leaf->command, $leaf->wrap, $leaf->scope], CmdLeaf::all($quit)));
        self::assertSame([], CmdLeaf::all(null));
    }

    private static function named(Message $message): string
    {
        self::assertInstanceOf(TestMessage::class, $message);

        return $message->value;
    }
}
