<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\WidgetTree;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverlayTest extends TestCase
{
    #[Test]
    public function implementsWidgetContainer(): void
    {
        $overlay = Overlay::new(Text::new('base'), Text::new('over'));

        self::assertInstanceOf(WidgetContainer::class, $overlay);
    }

    #[Test]
    public function widgetTreeResolvesBothBaseAndOverlayAtTheFullArea(): void
    {
        $base = Text::new('base');
        $over = Text::new('over');
        $overlay = Overlay::new($base, $over);
        $screen = Rect::fromSize(80, 24);

        $rects = WidgetTree::resolveRects($overlay, $screen);

        self::assertTrue($rects->offsetExists($base), 'WidgetTree must resolve base widget when nested inside Overlay');
        self::assertTrue($rects->offsetExists($over), 'WidgetTree must resolve overlay widget when nested inside Overlay');
        self::assertEquals($screen, $rects[$base]);
        self::assertEquals($screen, $rects[$over]);
    }
}
