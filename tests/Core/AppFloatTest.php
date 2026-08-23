<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Popup\Floating;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppFloatTest extends TestCase
{
    #[Test]
    public function anAppDrawsTheFloatsItsViewQueued(): void
    {
        $harness = ModelHarness::for(new FloatingApp(), 30, 8, Theme::dark());

        self::assertStringContainsString(
            'FLOATED',
            $harness->renderScreen()->plainText(),
            'The harness renders through App::render(), so it must drain too',
        );
    }
}

final readonly class FloatingApp extends App
{
    public function view(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, self::popup(), new Rect(0, 0, 10, 1));
            }

            private static function popup(): Floating
            {
                return new class implements Floating {
                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return new Rect(0, 3, 10, 1);
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, 'FLOATED');
                    }
                };
            }
        };
    }
}
