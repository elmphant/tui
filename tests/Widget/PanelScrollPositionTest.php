<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelScrollPositionTest extends TestCase
{
    #[Test]
    public function aPanelWhoseContentFitsIsAtTheTopAndAtTheEnd(): void
    {
        $panel = self::panelOf(3);

        self::assertTrue($panel->isScrolledToTheTop(self::area()));
        self::assertTrue($panel->isScrolledToTheEnd(self::area()));
    }

    #[Test]
    public function aLongPanelStartsAtTheTopAndNotAtTheEnd(): void
    {
        $panel = self::panelOf(30);

        self::assertTrue($panel->isScrolledToTheTop(self::area()));
        self::assertFalse($panel->isScrolledToTheEnd(self::area()));
    }

    #[Test]
    public function aLongPanelScrolledDownIsAtTheEndAndNotAtTheTop(): void
    {
        $panel = self::panelOf(30)->withScrollOffset(1_000);

        self::assertFalse($panel->isScrolledToTheTop(self::area()));
        self::assertTrue($panel->isScrolledToTheEnd(self::area()));
    }

    #[Test]
    public function aLongPanelScrolledPartWayIsAtNeitherEnd(): void
    {
        $panel = self::panelOf(30)->withScrollOffset(5);

        self::assertFalse($panel->isScrolledToTheTop(self::area()));
        self::assertFalse($panel->isScrolledToTheEnd(self::area()));
    }

    private static function panelOf(int $lines): Panel
    {
        return Panel::new('Log')->withChildren([Paragraph::fromText(implode("\n", array_map(static fn (int $line): string => "line {$line}", range(1, $lines))))]);
    }

    private static function area(): Rect
    {
        return Rect::fromSize(30, 8);
    }
}
