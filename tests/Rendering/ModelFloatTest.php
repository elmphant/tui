<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Floating;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

/**
 * A popup must not depend on which base class the application chose: a model
 * that paints its own screen can float something over it too.
 */
final class ModelFloatTest extends TestCase
{
    #[Test]
    public function aModelPaintingItsOwnScreenCanStillFloatSomethingOverIt(): void
    {
        $terminal = new VirtualTerminal(30, 6);
        // After a frame, not before: quitting first leaves nothing painted.
        EventLoop::delay(0.15, static function () use ($terminal): void {
            $terminal->simulateInput('q');
        });

        Program::new(new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this, $message instanceof KeyMsg ? Cmd::quit() : null);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
                for ($row = 0; $row < 6; ++$row) {
                    $frame->putStyled(0, $row, str_repeat('.', 30), $context->theme->base);
                }
                $context->float($frame, new class implements Floating {
                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return new Rect($anchor->x, $anchor->bottom(), 5, 1);
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(5), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putStyled($area->x, $area->y, 'PEEK!', $context->theme->accent);
                    }
                }, new Rect(2, 1, 10, 1));
            }
        })->withTerminal($terminal)->start();

        $screen = new ScreenBuffer($terminal->getOutput(), 30, 6);

        self::assertSame('PEEK!', $screen->getTextAt(2, 2, 5), 'the float paints over the row below its anchor');
    }
}
