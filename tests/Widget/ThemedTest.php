<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\ComponentRegistry;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\Floating;
use Elmphant\Tui\Rendering\Context\FloatQueue;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Themed;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThemedTest extends TestCase
{
    #[Test]
    public function onlyTheWidgetInsideDrawsWithTheAdjustedTheme(): void
    {
        $red = Color::hex('#ff0000');
        $view = Layout::column(
            Themed::around(Text::line(Span::token('in', StyleToken::Accent)), static fn (Theme $theme): Theme => $theme->withAccent(new Style(foreground: $red))),
            Text::line(Span::token('out', StyleToken::Accent)),
        );
        $frame = new Frame(5, 2);

        $view->render($frame, new Rect(0, 0, 5, 2), RenderContext::unfocused(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertEquals($red, $screen->styleAt(0, 0)->foreground);
        self::assertEquals(Theme::dark()->accent->foreground, $screen->styleAt(0, 1)->foreground);
    }

    #[Test]
    public function aPopupOpenedInsideDrawsWithTheAdjustedThemeToo(): void
    {
        $red = Color::hex('#ff0000');
        $popup = new class implements Floating {
            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $anchor;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                Text::line(Span::token('p', StyleToken::Accent))->render($frame, $area, $context);
            }
        };
        $opener = new class($popup) implements Widget {
            public function __construct(private readonly Floating $popup)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, $this->popup, $area);
            }
        };
        $floats = new FloatQueue();
        $context = new RenderContext(Theme::dark(), focus: FocusPath::nowhere(), floats: $floats);
        $frame = new Frame(1, 1);

        $floats->drawOver($frame, static function () use ($opener, $red, $frame, $context): void {
            Themed::around($opener, static fn (Theme $theme): Theme => $theme->withAccent(new Style(foreground: $red)))
                ->render($frame, new Rect(0, 0, 1, 1), $context);
        }, $context);

        self::assertEquals($red, ScreenBuffer::fromFrame($frame)->styleAt(0, 0)->foreground);
    }

    #[Test]
    public function itIsAsTallAsTheChildItThemes(): void
    {
        $keep = static fn (Theme $theme): Theme => $theme;
        $fixed = new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(4), Constraint::exact(3));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };

        self::assertSame(
            [3, 1],
            [Themed::around($fixed, $keep)->measureHeight(10), Themed::around(Text::new('one'), $keep)->measureHeight(10)],
        );
    }

    #[Test]
    public function aComponentInsideIsStillFoundByTheApp(): void
    {
        $field = Component::bind(ComponentId::named('name'), TextInput::new(), static fn (App $app, TextInput $input): App => $app);

        $registry = ComponentRegistry::fromWidget(Themed::around($field, static fn (Theme $theme): Theme => $theme));

        self::assertSame([$field], $registry->all());
    }
}
