<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramResizeAtStartupTest extends TestCase
{
    #[Test]
    #[RequiresFunction('posix_kill')]
    #[RequiresFunction('pcntl_signal')]
    public function aResizeWhileTheProgramStartsIsNotLost(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $model = new ResizedWhileStartingModel($terminal);

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(100, $model->lastWidth);
    }
}

final class ResizedWhileStartingModel implements Model
{
    public ?int $lastWidth = null;

    public function __construct(private readonly VirtualTerminal $terminal)
    {
    }

    public function init(): ?Cmd
    {
        $this->terminal->resizeTo(100, 30);
        posix_kill(getmypid(), SIGWINCH);

        return Cmd::after(0.2, static fn () => Cmd::quit());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ResizeMsg) {
            $this->lastWidth = $message->width;
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
