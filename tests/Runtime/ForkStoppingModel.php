<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
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

    public function __construct(private readonly bool $cancels, private readonly ?Message $sendsFirst = null)
    {
    }

    public function init(): ?Cmd
    {
        $sendsFirst = $this->sendsFirst;

        return Cmd::fork(static function (Closure $send) use ($sendsFirst): Message {
            $send(new ForkSaid((string)posix_getpid(), last: false));
            if ($sendsFirst !== null) {
                $send($sendsFirst);
            }
            sleep(10);

            return new ForkSaid('late');
        }, EffectId::named('slow'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ErrorMsg) {
            $this->log[] = 'error from ' . $message->effectId . ': ' . $message->source->value . ' ' . $message->error->getMessage();

            return new UpdateResult($this, Cmd::quit());
        }

        if (!$message instanceof ForkSaid) {
            return new UpdateResult($this);
        }

        if ($this->childPid === null) {
            $this->childPid = (int)$message->what;
            if ($this->sendsFirst !== null) {
                return new UpdateResult($this);
            }
            $this->log[] = $message->what;

            return new UpdateResult($this, $this->cancels
                ? Cmd::batch(Cmd::cancel(EffectId::named('slow')), Cmd::after(0.05, static fn (): Message => new ForkSaid('quiet')))
                : Cmd::quit());
        }

        $this->log[] = $message->what;

        return new UpdateResult($this, Cmd::quit());
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
