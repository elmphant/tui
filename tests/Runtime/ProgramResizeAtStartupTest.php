<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
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
        self::assertLessThan(0.1, $model->resizedAfter, 'it is asked for again at once, not when something else happens to wake the loop');
    }
}

final class ResizedWhileStartingModel implements Model
{
    public ?int $lastWidth = null;
    public ?float $resizedAfter = null;
    private float $startedAt = 0.0;

    public function __construct(private readonly VirtualTerminal $terminal)
    {
    }

    public function init(): ?Cmd
    {
        $this->startedAt = microtime(true);
        $this->terminal->resizeTo(100, 30);
        posix_kill(getmypid(), SIGWINCH);

        return Cmd::after(0.2, static fn () => Cmd::quit());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ResizeMsg) {
            $this->lastWidth = $message->width;
            if ($message->width === 100) {
                $this->resizedAfter ??= microtime(true) - $this->startedAt;
            }
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
