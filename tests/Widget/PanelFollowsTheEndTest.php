<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelFollowsTheEndTest extends TestCase
{
    private const AREA_WIDTH = 20;
    private const AREA_HEIGHT = 5;

    #[Test]
    public function aFollowedChildIsReportedAtTheOffsetItIsDrawnAt(): void
    {
        $panel = self::panelOfLines(20)->withFollowChild(19);

        self::assertStringContainsString('line19', self::screenOf($panel));
        self::assertSame(17, $panel->scrollOffset(self::area()));
    }

    #[Test]
    public function aPaneFollowingTheEndShowsAChildAddedBelowTheFold(): void
    {
        $panel = self::panelOfLines(20)->withFollowingTheEnd();

        $grown = $panel->add(Text::new('line20'));

        self::assertStringContainsString('line20', self::screenOf($grown));
        self::assertTrue($grown->isFollowingTheEnd());
    }

    #[Test]
    public function aReaderWhoScrolledUpStaysWhereTheyAreWhenAChildArrives(): void
    {
        $panel = self::wheeled(self::panelOfLines(20)->withFollowingTheEnd(), MouseAction::WheelUp);
        $before = self::screenOf($panel);

        $grown = $panel->add(Text::new('line20'));

        self::assertFalse($grown->isFollowingTheEnd());
        self::assertSame($before, self::screenOf($grown));
    }

    #[Test]
    public function scrollingBackToTheEndFollowsItAgain(): void
    {
        $away = self::wheeled(self::panelOfLines(20)->withFollowingTheEnd(), MouseAction::WheelUp);

        $back = self::wheeled($away, MouseAction::WheelDown)->add(Text::new('line20'));

        self::assertTrue($back->isFollowingTheEnd());
        self::assertStringContainsString('line20', self::screenOf($back));
    }

    private static function wheeled(Panel $panel, MouseAction $direction): Panel
    {
        return $panel->update(new MouseMsg(5, 2, $direction, MouseButton::None), self::area())->model;
    }

    private static function area(): Rect
    {
        return new Rect(0, 0, self::AREA_WIDTH, self::AREA_HEIGHT);
    }

    private static function panelOfLines(int $count): Panel
    {
        return Panel::new('log')->withChildren(array_map(
            static fn (int $index): Text => Text::new("line{$index}"),
            range(0, $count - 1),
        ));
    }

    private static function screenOf(Panel $panel): string
    {
        $frame = new Frame(self::AREA_WIDTH, self::AREA_HEIGHT);
        $panel->render($frame, self::area(), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame)->plainText();
    }
}
