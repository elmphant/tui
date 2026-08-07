<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\DropdownPopup;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\Select;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupPointerTest extends TestCase
{
    #[Test]
    public function itReportsTheTokenItWasBuiltWith(): void
    {
        $target = new FloatTarget();

        self::assertSame($target, self::popup(['a'], $target)->target());
    }

    #[Test]
    public function itCapturesPressAndBothWheelsAndNothingElse(): void
    {
        $popup = self::popup(['a']);

        self::assertTrue($popup->captures(MouseAction::Press));
        self::assertTrue($popup->captures(MouseAction::WheelUp));
        self::assertTrue($popup->captures(MouseAction::WheelDown));
        self::assertFalse($popup->captures(MouseAction::Motion), 'a drag must reach whatever lies beneath');
        self::assertFalse($popup->captures(MouseAction::Release), 'a Split divider needs its Release');
    }

    #[Test]
    public function theBorderIsAllThePopupKeepsForItselfAndTheContentAnswersTheRest(): void
    {
        $popup = self::popup(['a', 'b', 'c']);
        $placement = new Rect(0, 4, 10, 5);
        $inner = $popup->contentArea($placement);

        self::assertEquals(new Rect(1, 5, 8, 3), $inner, 'the border is not the content to answer for');
        self::assertSame(
            'b',
            $popup->content()->optionAt(new MouseMsg(2, 6, MouseAction::Press, MouseButton::Left), $inner),
            'rows inside are addressed by the content, by name',
        );
        self::assertNull(
            $popup->content()->optionAt(new MouseMsg(2, 4, MouseAction::Press, MouseButton::Left), $inner),
            'and the border row is outside it',
        );
    }

    #[Test]
    public function aPlacementTooNarrowToDrawAnythingIsEmpty(): void
    {
        $placement = self::popup(['a', 'b'])->placement(new Rect(0, 0, 2, 1), Rect::fromSize(2, 20));

        self::assertSame(0, $placement->width, 'a popup that paints nothing must not claim the cells');
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function aPlacementTooShortToDrawAnythingIsEmpty(): void
    {
        $placement = self::popup(['a', 'b'])->placement(new Rect(0, 0, 10, 1), Rect::fromSize(10, 3));

        self::assertSame(0, $placement->width);
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function anEmptyOptionListBehindANarrowAnchorIsNotPlaced(): void
    {
        $placement = self::popup([])->placement(new Rect(0, 0, 2, 1), Rect::fromSize(80, 24));

        self::assertSame(0, $placement->width, 'two cells cannot hold two borders and anything between them');
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function aPlacementExactlyThreeCellsWideStillDraws(): void
    {
        $placement = self::popup(['a'])->placement(new Rect(0, 0, 3, 1), Rect::fromSize(3, 20));

        self::assertSame(3, $placement->width, 'three cells is the narrowest the popup can draw in');
        self::assertGreaterThanOrEqual(3, $placement->height);
    }

    #[Test]
    public function aPlacementExactlyThreeRowsHighStillDraws(): void
    {
        $placement = self::popup(['a'])->placement(new Rect(0, 0, 10, 1), Rect::fromSize(10, 4));

        self::assertSame(3, $placement->height, 'three rows is the shortest the popup can draw in');
        self::assertGreaterThanOrEqual(3, $placement->width);
    }

    /**
     * @param list<string> $options
     */
    private static function popup(array $options, ?FloatTarget $target = null): DropdownPopup
    {
        return DropdownPopup::of(Select::new($options), $target ?? new FloatTarget());
    }
}
