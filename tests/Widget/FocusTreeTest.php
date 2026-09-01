<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\Focus\FocusTree;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusTreeTest extends TestCase
{
    #[Test]
    public function aTreeWithNothingFocusedStepsOntoTheEndItCameFrom(): void
    {
        $tree = new FocusTree(3, null);

        self::assertSame(0, $tree->next()->active(), 'Stepping forward from nothing lands on the first node');
        self::assertSame(2, $tree->previous()->active(), 'and stepping back from nothing lands on the last');
    }

    #[Test]
    public function reportsWhichNodeIsActive(): void
    {
        $tree = new FocusTree(3);

        self::assertSame(0, $tree->active());
        self::assertSame(1, $tree->next()->active());
        self::assertSame(2, $tree->focusAtEnd()->active());
    }

    #[Test]
    public function steppingPastTheLastNodeComesBackToTheFirst(): void
    {
        $tree = new FocusTree(2);

        self::assertSame(0, $tree->next()->next()->active());
    }

    #[Test]
    public function steppingBackTakesTheNodeBefore(): void
    {
        $tree = new FocusTree(3, active: 2);

        self::assertSame(1, $tree->previous()->active());
    }

    #[Test]
    public function steppingBackFromTheFirstNodeLandsOnTheLast(): void
    {
        $tree = new FocusTree(3, active: 0);

        self::assertSame(2, $tree->previous()->active());
    }

    #[Test]
    public function focusGoesToTheNodeAsked(): void
    {
        $tree = new FocusTree(3);

        self::assertSame(2, $tree->focusAt(2)->active());
    }

    #[Test]
    public function anIndexBelowZeroLeavesFocusWhereItWas(): void
    {
        $tree = new FocusTree(2, active: 1);

        self::assertSame(1, $tree->focusAt(-1)->active());
    }

    #[Test]
    public function anIndexBeyondTheLastNodeLeavesFocusWhereItWas(): void
    {
        $tree = new FocusTree(3, active: 1);

        self::assertSame(1, $tree->focusAt(3)->active(), 'One past the last is already too far');
        self::assertSame(1, $tree->focusAt(5)->active());
    }

    #[Test]
    public function aClickTakesFocusToTheNodeItLandsIn(): void
    {
        $tree = new FocusTree(3);
        $click = new MouseMsg(15, 5, MouseAction::Press, MouseButton::Left);

        $clicked = $tree->focusByClick(
            $click,
            new Rect(0, 0, 10, 10),
            new Rect(10, 0, 10, 10),
            new Rect(20, 0, 10, 10),
        );

        self::assertSame(1, $clicked->active());
    }

    #[Test]
    public function aClickOutsideEveryNodeLeavesFocusWhereItWas(): void
    {
        $tree = new FocusTree(2, active: 1);
        $click = new MouseMsg(50, 50, MouseAction::Press, MouseButton::Left);

        $clicked = $tree->focusByClick($click, new Rect(0, 0, 10, 10), new Rect(10, 0, 10, 10));

        self::assertSame(1, $clicked->active());
    }

    #[Test]
    public function aClickOnAnAreaBeyondTheLastNodeLeavesFocusWhereItWas(): void
    {
        $tree = new FocusTree(2, active: 0);
        $click = new MouseMsg(25, 5, MouseAction::Press, MouseButton::Left);

        $clicked = $tree->focusByClick(
            $click,
            new Rect(0, 0, 10, 10),
            new Rect(10, 0, 10, 10),
            new Rect(20, 0, 10, 10),
        );

        self::assertSame(0, $clicked->active());
    }

    #[Test]
    public function theLastNodeWouldWrapGoingForward(): void
    {
        self::assertTrue((new FocusTree(3, active: 2))->wouldWrap(false));
    }

    #[Test]
    public function theFirstNodeWouldWrapGoingBack(): void
    {
        self::assertTrue((new FocusTree(3, active: 0))->wouldWrap(true));
    }

    #[Test]
    public function aNodeInTheMiddleWouldWrapNeitherWay(): void
    {
        $tree = new FocusTree(3, active: 1);

        self::assertFalse($tree->wouldWrap(false));
        self::assertFalse($tree->wouldWrap(true));
    }

    #[Test]
    public function aLoneNodeWouldWrapEitherWay(): void
    {
        $tree = new FocusTree(1, active: 0);

        self::assertTrue($tree->wouldWrap(false));
        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function aLoneNodeWouldWrapEvenWithAnActiveBeyondIt(): void
    {
        $tree = new FocusTree(1, active: 1);

        self::assertTrue($tree->wouldWrap(false));
        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function anEmptyTreeWouldWrapEitherWay(): void
    {
        $tree = new FocusTree();

        self::assertTrue($tree->wouldWrap(false));
        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function anEmptyTreeStaysAsItIsWhenSteppedThrough(): void
    {
        $tree = new FocusTree();

        self::assertSame($tree, $tree->next());
        self::assertSame($tree, $tree->previous());
    }
}
