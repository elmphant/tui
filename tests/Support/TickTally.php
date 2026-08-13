<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Interactive;

/**
 * Test double: an interactive widget that changes state on every TickMsg,
 * for asserting that containers forward messages to their children.
 */
final readonly class TickTally implements Interactive
{
    public function __construct(public int $ticks = 0)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof TickMsg) {
            return new UpdateResult(new self($this->ticks + 1));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $frame->putString($area->x, $area->y, (string)$this->ticks);
    }
}
