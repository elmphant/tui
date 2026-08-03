<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Floating;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderContextRenderRootTest extends TestCase
{
    #[Test]
    public function aFloatDrawsOutsideTheClipTheTreeRenderedUnder(): void
    {
        $context = new RenderContext(Theme::dark());
        $frame = new Frame(40, 10);

        $context->renderRoot(self::host('POPUP', new Rect(0, 1, 40, 2)), $frame, $frame->area());

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertStringContainsString('base', $screen->getLine(1), 'The tree still drew');
        self::assertStringContainsString('POPUP', $screen->getLine(4), 'and the float escaped the clip');
    }

    #[Test]
    public function theLastFloatQueuedDrawsOverTheOnesBeforeIt(): void
    {
        $context = new RenderContext(Theme::dark());
        $frame = new Frame(40, 10);
        $anchor = new Rect(0, 0, 10, 1);

        $context->renderRoot(
            self::twoFloats($context, $anchor),
            $frame,
            $frame->area(),
        );

        self::assertStringContainsString('second', ScreenBuffer::fromFrame($frame)->getLine(3));
    }

    private static function twoFloats(RenderContext $context, Rect $anchor): Widget
    {
        return new class($anchor) implements Widget {
            public function __construct(private readonly Rect $anchor)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::flex();
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, self::labelled('first '), $this->anchor);
                $context->float($frame, self::labelled('second'), $this->anchor);
            }

            private static function labelled(string $text): Floating
            {
                return new class($text) implements Floating {
                    public function __construct(private readonly string $text)
                    {
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return new Rect($anchor->x, $anchor->y + 3, 10, 1);
                    }

                    public function constraint(): Constraint
                    {
                        return Constraint::exact(1);
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, $this->text);
                    }
                };
            }
        };
    }

    /**
     * A widget that clips itself to two rows, draws inside, and floats a
     * popup four rows down — outside its own clip.
     */
    private static function host(string $text, Rect $clip): Widget
    {
        return new class($text, $clip) implements Widget {
            public function __construct(
                private readonly string $text,
                private readonly Rect $clip,
            ) {
            }

            public function constraint(): Constraint
            {
                return Constraint::flex();
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->pushClip($this->clip);

                try {
                    $frame->putString($area->x, $this->clip->y, 'base');
                    $context->float($frame, self::popup($this->text), new Rect(0, $this->clip->y, 10, 1));
                } finally {
                    $frame->popClip();
                }
            }

            private static function popup(string $text): Floating
            {
                return new class($text) implements Floating {
                    public function __construct(private readonly string $text)
                    {
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return new Rect($anchor->x, $anchor->y + 3, 10, 1);
                    }

                    public function constraint(): Constraint
                    {
                        return Constraint::exact(1);
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, $this->text);
                    }
                };
            }
        };
    }
}
