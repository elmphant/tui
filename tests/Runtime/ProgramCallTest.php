<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\ScriptedModel;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class ProgramCallTest extends TestCase
{
    #[Test]
    public function aCallRunsOnceAndWhatItAnswersReachesTheModel(): void
    {
        $calls = new ArrayObject();

        $seen = self::seenAfter(Cmd::call(static function () use ($calls): Message {
            $calls->append(true);

            return new TestMessage('called');
        }));

        self::assertCount(1, $calls);
        self::assertSame(['called', 'done'], self::values($seen));
    }

    #[Test]
    public function aCommandACallAnswersWithIsRun(): void
    {
        $seen = self::seenAfter(Cmd::call(static fn (): Cmd => Cmd::message(new TestMessage('from the command'))));

        self::assertSame(['from the command', 'done'], self::values($seen));
    }

    #[Test]
    public function aCallThatAnswersNothingAddsNoMessage(): void
    {
        $seen = self::seenAfter(Cmd::call(static fn () => null));

        self::assertSame(['done'], self::values($seen));
    }

    #[Test]
    public function aCallThatFailsReachesTheModelAsACallError(): void
    {
        $failure = new RuntimeException('call failed');

        $seen = self::seenAfter(Cmd::call(static fn () => throw $failure));

        $errors = array_values(array_filter($seen, static fn (Message $message): bool => $message instanceof ErrorMsg));
        self::assertCount(1, $errors);
        self::assertSame($failure, $errors[0]->error);
        self::assertSame(ErrorSource::Call, $errors[0]->source);
    }

    #[Test]
    public function aCallThatAnswersNeitherAMessageNorACommandReachesTheModelAsACallError(): void
    {
        /** @var Closure(): (Message|Cmd|null) $answersAString */
        $answersAString = static fn (): string => 'neither';

        $seen = self::seenAfter(Cmd::call($answersAString));

        $errors = array_values(array_filter($seen, static fn (Message $message): bool => $message instanceof ErrorMsg));
        self::assertCount(1, $errors);
        self::assertInstanceOf(UnexpectedValueException::class, $errors[0]->error);
        self::assertSame(ErrorSource::Call, $errors[0]->source);
    }

    /**
     * @return list<Message>
     */
    private static function seenAfter(Cmd $call): array
    {
        $seen = new ArrayObject();

        Program::new(new ScriptedModel(
            render: static function (): void {
            },
            atStart: Cmd::batch($call, Cmd::message(new TestMessage('done'))),
            onMessage: static function (Message $message) use ($seen): ?Cmd {
                $seen->append($message);

                return $message instanceof TestMessage && $message->value === 'done' ? Cmd::quit() : null;
            },
        ))->withTerminal(GuardedTerminal::of(20, 3))->start();

        return array_values($seen->getArrayCopy());
    }

    /**
     * @param list<Message> $seen
     *
     * @return list<string>
     */
    private static function values(array $seen): array
    {
        return array_values(array_map(
            static fn (TestMessage $message): string => $message->value,
            array_filter($seen, static fn (Message $message): bool => $message instanceof TestMessage),
        ));
    }
}
