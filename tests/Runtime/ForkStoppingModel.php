<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;

final class ForkStoppingModel implements Model
{
    public ?int $childPid = null;

    /**
     * @var list<string>
     */
    public array $log = [];

    public function __construct(private readonly bool $cancels)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::fork(static function (Closure $send): Message {
            $send(new ForkSaid((string)posix_getpid(), last: false));
            sleep(10);

            return new ForkSaid('late');
        }, EffectId::named('slow'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof ForkSaid) {
            return new UpdateResult($this);
        }

        $this->log[] = $message->what;

        if ($this->childPid === null) {
            $this->childPid = (int)$message->what;

            return new UpdateResult($this, $this->cancels
                ? Cmd::batch(Cmd::cancel(EffectId::named('slow')), Cmd::after(0.05, static fn (): Message => new ForkSaid('quiet')))
                : Cmd::quit());
        }

        return new UpdateResult($this, Cmd::quit());
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
