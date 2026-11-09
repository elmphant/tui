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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramTerminalSizeBoundsTest extends TestCase
{
    #[Test]
    public function aTerminalLargerThanAnyScreenIsDrawnAtTheLargestSize(): void
    {
        $model = new SizeRecordingModel();

        Program::new($model)->withTerminal(new VirtualTerminal(1200, 600))->start();

        self::assertSame([[1000, 500]], $model->resizes);
        self::assertSame([1000, 500], $model->frameSize);
    }

    #[Test]
    public function aTerminalReportingNoRoomIsDrawnOneCellLarge(): void
    {
        $model = new SizeRecordingModel();

        Program::new($model)->withTerminal(new VirtualTerminal(-5, 0))->start();

        self::assertSame([[1, 1]], $model->resizes);
        self::assertSame([1, 1], $model->frameSize);
    }
}

final class SizeRecordingModel implements Model
{
    /**
     * @var list<array{int, int}>
     */
    public array $resizes = [];

    /**
     * @var ?array{int, int}
     */
    public ?array $frameSize = null;

    public function init(): ?Cmd
    {
        return Cmd::after(0.1, static fn () => Cmd::quit());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ResizeMsg) {
            $this->resizes[] = [$message->width, $message->height];
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->frameSize = [$frame->width, $frame->height];
    }
}
