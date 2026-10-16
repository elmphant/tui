<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Widget\Popup\PointerFloating;
use Elmphant\Tui\Widget\Widget;
use RuntimeException;

/**
 * A model whose root pass queues one float, for runtime tests that need a
 * drawn pointer target.
 */
final class FloatingModel implements Model
{
    public ?Message $received = null;
    public int $messagesSeen = 0;
    public bool $throwAfterRenderRoot = false;

    public function __construct(public PointerFloating $float)
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg || $message instanceof FloatMouseMsg) {
            $this->received = $message;
            ++$this->messagesSeen;
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        RootPass::draw($pass, self::widgetQueuing($this->float), $frame, $frame->area());

        if ($this->throwAfterRenderRoot) {
            throw new RuntimeException('the model drew its floats and then failed');
        }
    }

    private static function widgetQueuing(PointerFloating $float): Widget
    {
        return new class($float) implements Widget {
            public function __construct(private readonly PointerFloating $float)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, $this->float, new Rect($area->x, $area->y, 1, 1));
            }
        };
    }
}
