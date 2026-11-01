<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;

final readonly class ScriptedModel implements Model
{
    /**
     * @param Closure(Frame, RenderPass): void $render
     * @param (Closure(Message): ?Cmd)|null    $onMessage
     */
    public function __construct(
        private Closure $render,
        private ?Cmd $atStart = null,
        private ?Closure $onMessage = null,
    ) {
    }

    public function init(): ?Cmd
    {
        return $this->atStart;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof Stop) {
            return new UpdateResult($this, Cmd::quit());
        }

        $answer = $this->onMessage === null ? null : ($this->onMessage)($message);

        return new UpdateResult($message instanceof Ping ? new self($this->render, null, $this->onMessage) : $this, $answer);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        ($this->render)($frame, $pass);
    }
}
