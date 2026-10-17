<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\DecoratesOneChild;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecoratesOneChildTest extends TestCase
{
    #[Test]
    public function aDecoratorSizesPlacesAndDrawsAsTheChildItWraps(): void
    {
        $child = Text::new('wrapped');
        $decorator = new PlainDecorator($child);
        $area = Rect::fromSize(20, 1);
        $frame = new Frame(20, 1);

        $decorator->render($frame, $area, RenderContext::unfocused(Theme::dark()));

        self::assertEquals($child->sizing(), $decorator->sizing());
        self::assertSame([$child], [...$decorator->children()]);
        self::assertSame([[$child, $area]], [...$decorator->placedChildren($area)]);
        self::assertSame('wrapped', implode('', \array_slice($frame->characters, 0, 7)));
    }

    #[Test]
    public function aDecoratorMayStillAnswerOneOfThemItself(): void
    {
        $decorator = new TallDecorator(Text::new('wrapped'));

        self::assertEquals(Constraint::exact(3), $decorator->sizing()->rows);
    }
}

final readonly class PlainDecorator implements Widget, WidgetContainer
{
    use DecoratesOneChild;

    public function __construct(private Widget $child)
    {
    }

    private function wrapped(): Widget
    {
        return $this->child;
    }
}

final readonly class TallDecorator implements Widget, WidgetContainer
{
    use DecoratesOneChild {
        sizing as private wrappedSizing;
    }

    public function __construct(private Widget $child)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of($this->wrappedSizing()->columns, Constraint::exact(3));
    }

    private function wrapped(): Widget
    {
        return $this->child;
    }
}
