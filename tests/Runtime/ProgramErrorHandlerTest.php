<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProgramErrorHandlerTest extends TestCase
{
    #[Test]
    public function anErrorHandlerThatKeepsFeedingAFailingUpdateIsGivenUpOn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retry failed');

        Program::new(new FailsOnRetryModel())
            ->withTerminal(new VirtualTerminal(20, 3))
            ->onError(static fn (ErrorMsg $error): Cmd => Cmd::message(new Retry()))
            ->start();
    }

    #[Test]
    public function anErrorNoHandlerHeardIsReportedOnceTheTerminalIsRestored(): void
    {
        $terminal = new VirtualTerminal(20, 3);

        Program::new(new FailsOnceThenQuitsModel())->withTerminal($terminal)->start();

        self::assertMatchesRegularExpression(
            '/1 error reached no onError\(\) handler\. The last one: RuntimeException: once in ' . preg_quote(__FILE__, '/') . ':\d+\r\n$/',
            $terminal->output(),
        );
    }
}

final readonly class Retry implements Message
{
}

final readonly class FailsOnceThenQuitsModel implements Model
{
    public function init(): ?Cmd
    {
        return Cmd::message(new Retry());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof Retry) {
            throw new RuntimeException("once\e]0;title\x07");
        }

        return new UpdateResult($this, $message instanceof ErrorMsg ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class FailsOnRetryModel implements Model
{
    public function init(): ?Cmd
    {
        return Cmd::message(new Retry());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof Retry) {
            throw new RuntimeException('retry failed');
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
