<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Plan\WidgetTree;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WidgetTreeTest extends TestCase
{
    #[Test]
    public function resolveRectsSingleWidget(): void
    {
        $text = Text::new('Hello');
        $area = new Rect(0, 0, 20, 10);

        $map = WidgetTree::resolveRects($text, $area);

        self::assertTrue($map->offsetExists($text));
        self::assertSame($area, $map[$text]);
    }

    #[Test]
    public function resolveRectsVerticalLayout(): void
    {
        $a = Text::new('A');
        $b = $this->createFlexWidget();
        $layout = Layout::vertical($a, $b);

        $map = WidgetTree::resolveRects($layout, new Rect(0, 0, 20, 10));

        self::assertTrue($map->offsetExists($a));
        self::assertSame(0, $map[$a]->y);
        self::assertSame(1, $map[$a]->height);

        self::assertTrue($map->offsetExists($b));
        self::assertSame(1, $map[$b]->y);
        self::assertSame(9, $map[$b]->height);
    }

    #[Test]
    public function resolveRectsNestedLayouts(): void
    {
        $deep = $this->createFlexWidget();
        $inner = Layout::horizontal($this->createFlexWidget(), $deep);
        $outer = Layout::vertical(Text::new('Top'), $inner);

        $map = WidgetTree::resolveRects($outer, new Rect(0, 0, 20, 10));

        self::assertTrue($map->offsetExists($deep));
        self::assertSame(10, $map[$deep]->x);
        self::assertSame(1, $map[$deep]->y);
        self::assertSame(9, $map[$deep]->height);
        self::assertSame(10, $map[$deep]->width);
    }

    #[Test]
    public function resolveRectsIncludesLayoutNodes(): void
    {
        $inner = Layout::horizontal($this->createFlexWidget());
        $outer = Layout::vertical(Text::new('Top'), $inner);

        $map = WidgetTree::resolveRects($outer, new Rect(0, 0, 20, 10));

        self::assertTrue($map->offsetExists($outer));
        self::assertTrue($map->offsetExists($inner));
        self::assertSame(0, $map[$outer]->y);
        self::assertSame(1, $map[$inner]->y);
        self::assertSame(9, $map[$inner]->height);
    }

    #[Test]
    public function findRectsForSpecificWidgets(): void
    {
        $a = Text::new('A');
        $b = $this->createFlexWidget();
        $layout = Layout::vertical($a, $b);

        $rects = WidgetTree::findRects($layout, new Rect(0, 0, 20, 10), [$b, $a]);

        self::assertCount(2, $rects);
        self::assertSame(1, $rects[0]->y);
        self::assertSame(9, $rects[0]->height);
        self::assertSame(0, $rects[1]->y);
        self::assertSame(1, $rects[1]->height);
    }

    #[Test]
    public function findRectsReturnsNullForMissingWidgets(): void
    {
        $layout = Layout::vertical(Text::new('A'));
        $missing = Text::new('Not in tree');

        $rects = WidgetTree::findRects($layout, new Rect(0, 0, 20, 10), [$missing]);

        self::assertCount(1, $rects);
        self::assertNull($rects[0]);
    }

    #[Test]
    public function descendsIntoPanelChildren(): void
    {
        $checkbox = Checkbox::new('Accept');
        $panel = Panel::new('Test')->add($checkbox);
        $layout = Layout::vertical($panel);

        $map = WidgetTree::resolveRects($layout, new Rect(0, 0, 40, 20));

        self::assertTrue($map->offsetExists($checkbox), 'WidgetTree should find children inside Panel');
    }

    #[Test]
    public function panelChildRectsHaveCorrectPositions(): void
    {
        $first = Text::new('A');
        $second = Text::new('B');
        $panel = Panel::new('Test')->add($first)->add($second);

        $map = WidgetTree::resolveRects($panel, new Rect(0, 0, 40, 20));

        self::assertTrue($map->offsetExists($first));
        self::assertTrue($map->offsetExists($second));
        $firstRect = $map[$first];
        $secondRect = $map[$second];
        self::assertSame($firstRect->y + $firstRect->height, $secondRect->y);
    }

    #[Test]
    public function descendsIntoTabBarActiveTabChildren(): void
    {
        $checkbox = Checkbox::new('Accept');
        $tabBar = TabBar::new()->addTab('Tab1')->add($checkbox);
        $layout = Layout::vertical($tabBar);

        $map = WidgetTree::resolveRects($layout, new Rect(0, 0, 40, 20));

        self::assertTrue($map->offsetExists($checkbox), 'WidgetTree should find children inside active tab');
    }

    #[Test]
    public function tabBarChildRectsHaveCorrectPositions(): void
    {
        $first = Text::new('A');
        $second = Text::new('B');
        $tabBar = TabBar::new()->addTab('Tab1')->add($first)->add($second);

        $map = WidgetTree::resolveRects($tabBar, new Rect(0, 0, 40, 20));

        self::assertTrue($map->offsetExists($first));
        self::assertTrue($map->offsetExists($second));
        $firstRect = $map[$first];
        $secondRect = $map[$second];
        self::assertSame($firstRect->y + $firstRect->height, $secondRect->y);
    }

    #[Test]
    public function descendsIntoModalContent(): void
    {
        $inner = Text::new('Hello');
        $modal = Modal::new('Title', $inner);
        $layout = Layout::vertical($modal);

        $map = WidgetTree::resolveRects($layout, new Rect(0, 0, 40, 20));

        self::assertTrue($map->offsetExists($inner), 'WidgetTree should find content inside Modal');
    }

    private function createFlexWidget(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
