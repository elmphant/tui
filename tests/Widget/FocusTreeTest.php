<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\FocusTree;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class FocusTreeTest extends TestCase
{
    #[Test]
    public function reportsWhichNodeIsActive(): void
    {
        $tree = new FocusTree(3);

        self::assertSame(0, $tree->active());
        self::assertSame(1, $tree->next()->active());
        self::assertSame(2, $tree->focusAtEnd()->active());
    }

    #[Test]
    public function nextAdvancesFocus(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3);

        $result = $tree->next()->apply($nodes);

        self::assertFalse($result[0]->isFocused());
        self::assertTrue($result[1]->isFocused());
        self::assertFalse($result[2]->isFocused());
    }

    #[Test]
    public function nextWrapsAround(): void
    {
        $nodes = $this->createNodes(2);
        $tree = new FocusTree(2);

        $result = $tree->next()->next()->apply($nodes);

        self::assertTrue($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
    }

    #[Test]
    public function prevGoesBack(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3, active: 2);

        $result = $tree->prev()->apply($nodes);

        self::assertFalse($result[0]->isFocused());
        self::assertTrue($result[1]->isFocused());
        self::assertFalse($result[2]->isFocused());
    }

    #[Test]
    public function prevWrapsAround(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3, active: 0);

        $result = $tree->prev()->apply($nodes);

        self::assertFalse($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
        self::assertTrue($result[2]->isFocused());
    }

    #[Test]
    public function focusAtSetsSpecificIndex(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3);

        $result = $tree->focusAt(2)->apply($nodes);

        self::assertFalse($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
        self::assertTrue($result[2]->isFocused());
    }

    #[Test]
    public function focusAtIgnoresNegativeIndex(): void
    {
        $nodes = $this->createNodes(2);
        $tree = new FocusTree(2);

        $result = $tree->focusAt(-1)->apply($nodes);

        self::assertTrue($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
    }

    #[Test]
    public function focusAtIgnoresOutOfRangeIndex(): void
    {
        $nodes = $this->createNodes(2);
        $tree = new FocusTree(2);

        $result = $tree->focusAt(5)->apply($nodes);

        self::assertTrue($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
    }

    #[Test]
    public function focusByClickFocusesWidgetWhoseRectContainsClick(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3);

        $click = new MouseMsg(15, 5, MouseAction::Press, MouseButton::Left);
        $areas = [
            new Rect(0, 0, 10, 10),
            new Rect(10, 0, 10, 10),
            new Rect(20, 0, 10, 10),
        ];

        $result = $tree->focusByClick($click, ...$areas)->apply($nodes);

        self::assertFalse($result[0]->isFocused());
        self::assertTrue($result[1]->isFocused());
        self::assertFalse($result[2]->isFocused());
    }

    #[Test]
    public function focusByClickReturnsSelfWhenClickOutsideAllAreas(): void
    {
        $nodes = $this->createNodes(2);
        $tree = new FocusTree(2);

        $click = new MouseMsg(50, 50, MouseAction::Press, MouseButton::Left);
        $areas = [
            new Rect(0, 0, 10, 10),
            new Rect(10, 0, 10, 10),
        ];

        $result = $tree->focusByClick($click, ...$areas)->apply($nodes);

        self::assertTrue($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
    }

    #[Test]
    public function applyReturnsFocusedStateCorrectly(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3, active: 1);

        $result = $tree->apply($nodes);

        self::assertCount(3, $result);
        self::assertFalse($result[0]->isFocused());
        self::assertTrue($result[1]->isFocused());
        self::assertFalse($result[2]->isFocused());
    }

    #[Test]
    public function wouldWrapForwardAtLastIndex(): void
    {
        $tree = new FocusTree(3, active: 2);

        self::assertTrue($tree->wouldWrap(false));
    }

    #[Test]
    public function wouldWrapBackwardAtFirstIndex(): void
    {
        $tree = new FocusTree(3, active: 0);

        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function wouldNotWrapForwardInMiddle(): void
    {
        $tree = new FocusTree(3, active: 1);

        self::assertFalse($tree->wouldWrap(false));
    }

    #[Test]
    public function wouldNotWrapBackwardInMiddle(): void
    {
        $tree = new FocusTree(3, active: 1);

        self::assertFalse($tree->wouldWrap(true));
    }

    #[Test]
    public function wouldWrapWithSingleElement(): void
    {
        $tree = new FocusTree(1, active: 0);

        self::assertTrue($tree->wouldWrap(false));
        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function wouldWrapWithEmptyTree(): void
    {
        $tree = new FocusTree();

        self::assertTrue($tree->wouldWrap(false));
        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function emptyTreeNextReturnsSelf(): void
    {
        $tree = new FocusTree();

        $result = $tree->next();

        self::assertSame($tree, $result);
    }

    #[Test]
    public function emptyTreePrevReturnsSelf(): void
    {
        $tree = new FocusTree();

        $result = $tree->prev();

        self::assertSame($tree, $result);
    }

    #[Test]
    public function emptyTreeApplyReturnsEmptyArray(): void
    {
        $tree = new FocusTree();

        self::assertSame([], $tree->apply([]));
    }

    #[Test]
    public function focusAtZero(): void
    {
        $tree = new FocusTree(3, 2);
        $focused = $tree->focusAt(0);
        $reflection = new ReflectionProperty(FocusTree::class, 'active');
        self::assertSame(0, $reflection->getValue($focused));
    }

    #[Test]
    public function nextPrevRoundTrip(): void
    {
        $tree = new FocusTree(4, 0);
        $forward = $tree->next()->next()->next()->next();
        $reflection = new ReflectionProperty(FocusTree::class, 'active');
        self::assertSame(0, $reflection->getValue($forward));
    }

    #[Test]
    public function focusByClickWithNoMatch(): void
    {
        $tree = new FocusTree(2, 0);
        $click = new MouseMsg(100, 100, MouseAction::Press, MouseButton::Left);
        $result = $tree->focusByClick($click, new Rect(0, 0, 10, 5), new Rect(0, 5, 10, 5));
        $reflection = new ReflectionProperty(FocusTree::class, 'active');
        self::assertSame(0, $reflection->getValue($result));
    }

    #[Test]
    public function wouldWrapReturnsTrueForSingleElementWithOutOfRangeActive(): void
    {
        $tree = new FocusTree(1, active: 1);

        self::assertTrue($tree->wouldWrap(false));
        self::assertTrue($tree->wouldWrap(true));
    }

    #[Test]
    public function focusAtIgnoresIndexEqualToCount(): void
    {
        $nodes = $this->createNodes(3);
        $tree = new FocusTree(3, active: 0);

        $result = $tree->focusAt(3)->apply($nodes);

        self::assertTrue($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
        self::assertFalse($result[2]->isFocused());
    }

    #[Test]
    public function focusByClickIgnoresAreasBeyondCount(): void
    {
        $nodes = $this->createNodes(2);
        $tree = new FocusTree(2, active: 0);

        $click = new MouseMsg(25, 5, MouseAction::Press, MouseButton::Left);
        $areas = [
            new Rect(0, 0, 10, 10),
            new Rect(10, 0, 10, 10),
            new Rect(20, 0, 10, 10),
        ];

        $result = $tree->focusByClick($click, ...$areas)->apply($nodes);

        self::assertTrue($result[0]->isFocused());
        self::assertFalse($result[1]->isFocused());
    }

    /**
     * @return list<Focusable>
     */
    private function createNodes(int $count): array
    {
        return array_map(
            fn () => $this->createFocusableWidget(),
            range(1, $count),
        );
    }

    private function createFocusableWidget(bool $focused = false): Focusable
    {
        return new class($focused) implements Focusable {
            public function __construct(private readonly bool $focused)
            {
            }

            public function isFocused(): bool
            {
                return $this->focused;
            }

            public function withFocus(bool $focused): static
            {
                return new static($focused);
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                throw new RuntimeException('Not implemented');
            }
        };
    }
}
