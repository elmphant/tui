<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;

final class FiberLoggingModel implements Model
{
    /**
     * @var list<string>
     */
    public array $log = [];

    public function __construct(private readonly ?Cmd $init = null)
    {
    }

    public function init(): ?Cmd
    {
        return $this->init;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof TickMsg && !in_array('tick', $this->log, true)) {
            $this->log[] = 'tick';
        }
        if ($message instanceof FiberDone) {
            $this->log[] = $message->what;

            return new UpdateResult($this, Cmd::quit());
        }
        if ($message instanceof ErrorMsg) {
            $from = $message->effectId === null ? '' : ' from ' . $message->effectId;
            $this->log[] = 'error' . $from . ': ' . $message->source->value . ' ' . $message->error->getMessage();

            return new UpdateResult($this, Cmd::quit());
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
