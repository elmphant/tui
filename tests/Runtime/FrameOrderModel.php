<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;

final class FrameOrderModel implements Model
{
    /**
     * @var list<string>
     */
    public array $log = [];

    public function init(): ?Cmd
    {
        return Cmd::fiber(function (): Message {
            $this->log[] = 'work';

            return new FiberDone('loaded');
        });
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $message instanceof FiberDone ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        if (end($this->log) !== 'frame') {
            $this->log[] = 'frame';
        }
    }
}
