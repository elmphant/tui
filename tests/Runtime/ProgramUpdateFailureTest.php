<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProgramUpdateFailureTest extends TestCase
{
    #[Test]
    public function anUpdateThatKeepsFailingIsGivenUpOnAfterTenTries(): void
    {
        $calls = new ArrayObject();

        try {
            Program::new(new FailsExceptOnCallModel($calls))->withTerminal(GuardedTerminal::of(20, 3))->start();
            self::fail('The program must give up');
        } catch (RuntimeException $error) {
            self::assertSame('update 10 failed', $error->getMessage());
        }

        self::assertCount(10, $calls);
    }

    #[Test]
    public function anUpdateThatWorksStartsTheCountOfFailuresAgain(): void
    {
        $calls = new ArrayObject();

        try {
            Program::new(new FailsExceptOnCallModel($calls, succeedsOn: 6))->withTerminal(GuardedTerminal::of(20, 3))->start();
            self::fail('The program must give up');
        } catch (RuntimeException $error) {
            self::assertSame('update 16 failed', $error->getMessage());
        }

        self::assertCount(16, $calls);
    }
}

final readonly class FailsExceptOnCallModel implements Model
{
    /**
     * @param ArrayObject<int, Message> $calls
     */
    public function __construct(
        private ArrayObject $calls,
        private int $succeedsOn = 0,
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(...array_map(static fn (int $index): Cmd => Cmd::message(new TestMessage((string)$index)), range(1, 20)));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->calls->append($message);
        if (\count($this->calls) === $this->succeedsOn) {
            return new UpdateResult($this);
        }

        throw new RuntimeException(sprintf('update %d failed', \count($this->calls)));
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
