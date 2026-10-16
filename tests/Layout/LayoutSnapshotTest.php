<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Plan\LayoutSnapshot;
use Elmphant\Tui\Widget\Prose\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class LayoutSnapshotTest extends TestCase
{
    #[Test]
    public function equivalentAreaValuesReuseTheCachedLayout(): void
    {
        $owner = new stdClass();
        $calls = 0;
        $widget = Text::new('cached');
        $view = static function () use (&$calls, $widget): Text {
            ++$calls;

            return $widget;
        };

        LayoutSnapshot::rectsFor($owner, Rect::fromSize(80, 24), $view);
        LayoutSnapshot::rectsFor($owner, Rect::fromSize(80, 24), $view);

        self::assertSame(1, $calls);
    }

    #[Test]
    public function sameOwnerAndAreaReusesTheResolvedMapWithoutCallingView(): void
    {
        $owner = new stdClass();
        $area = Rect::fromSize(80, 24);
        $viewCalls = 0;
        $view = function () use (&$viewCalls) {
            ++$viewCalls;

            return Text::new('content');
        };

        $first = LayoutSnapshot::rectsFor($owner, $area, $view);
        $second = LayoutSnapshot::rectsFor($owner, $area, $view);

        self::assertSame($first, $second, 'Same owner and area must return the cached map instance');
        self::assertSame(1, $viewCalls, 'A cache hit must not re-invoke view()');
    }

    #[Test]
    public function differentAreaRecomputes(): void
    {
        $owner = new stdClass();
        $viewCalls = 0;
        $view = function () use (&$viewCalls) {
            ++$viewCalls;

            return Text::new('content');
        };

        $first = LayoutSnapshot::rectsFor($owner, Rect::fromSize(80, 24), $view);
        $second = LayoutSnapshot::rectsFor($owner, Rect::fromSize(100, 30), $view);

        self::assertNotSame($first, $second, 'A resize must invalidate the snapshot');
        self::assertSame(2, $viewCalls);
    }

    #[Test]
    public function differentOwnerRecomputes(): void
    {
        $area = Rect::fromSize(80, 24);
        $viewCalls = 0;
        $view = function () use (&$viewCalls) {
            ++$viewCalls;

            return Text::new('content');
        };

        LayoutSnapshot::rectsFor(new stdClass(), $area, $view);
        LayoutSnapshot::rectsFor(new stdClass(), $area, $view);

        self::assertSame(2, $viewCalls, 'A different owner identity must not share the snapshot');
    }

    #[Test]
    public function resizeBackToOriginalAreaRecomputesAgain(): void
    {
        $owner = new stdClass();
        $small = Rect::fromSize(80, 24);
        $large = Rect::fromSize(100, 30);
        $viewCalls = 0;
        $view = function () use (&$viewCalls) {
            ++$viewCalls;

            return Text::new('content');
        };

        LayoutSnapshot::rectsFor($owner, $small, $view);
        LayoutSnapshot::rectsFor($owner, $large, $view);
        LayoutSnapshot::rectsFor($owner, $small, $view);

        self::assertSame(3, $viewCalls, 'Only the most recent area is cached per owner');
    }

    #[Test]
    public function mapContainsResolvedWidgetRects(): void
    {
        $widget = Text::new('content');
        $area = Rect::fromSize(80, 24);

        $map = LayoutSnapshot::rectsFor(new stdClass(), $area, fn () => $widget);

        self::assertTrue($map->offsetExists($widget));
        self::assertSame($area, $map[$widget]);
    }
}
