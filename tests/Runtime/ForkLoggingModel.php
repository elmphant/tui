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

final class ForkLoggingModel implements Model
{
    /**
     * @var list<string>
     */
    public array $log = [];

    /**
     * @var list<Message>
     */
    public array $heard = [];

    public function __construct(private readonly ?Cmd $init = null)
    {
    }

    public function init(): ?Cmd
    {
        return $this->init;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ForkSaid) {
            $this->log[] = $message->what;
            $this->heard[] = $message;

            return new UpdateResult($this, $message->last ? Cmd::quit() : null);
        }
        if ($message instanceof ErrorMsg) {
            $from = $message->effectId === null ? '' : ' from ' . $message->effectId;
            $this->log[] = 'error' . $from . ': ' . $message->source->value . ' ' . $message->error->getMessage();
            $this->heard[] = $message;

            return new UpdateResult($this, Cmd::quit());
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
