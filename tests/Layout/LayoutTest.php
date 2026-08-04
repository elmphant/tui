<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\DirectionalConstraint;
use Elmphant\Tui\Widget\Paragraph;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    #[Test]
    public function verticalWithExactConstraints(): void
    {
        $layout = Layout::vertical(
            Text::new('Line 1'),
            Text::new('Line 2'),
            Text::new('Line 3'),
        );

        $frame = new Frame(20, 10);
        $layout->render($frame, $frame->area(), new RenderContext(Theme::dark()));

        self::assertSame('L', $frame->characters[0 * 20 + 0]);
        self::assertSame('L', $frame->characters[1 * 20 + 0]);
        self::assertSame('L', $frame->characters[2 * 20 + 0]);
    }

    #[Test]
    public function verticalWithFlexDistribution(): void
    {
        $widgetA = $this->createFlexWidget('A');
        $widgetB = $this->createFlexWidget('B');

        $layout = Layout::vertical($widgetA, $widgetB);
        $frame = new Frame(20, 10);
        $layout->render($frame, $frame->area(), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[0 * 20 + 0]);
        self::assertSame('B', $frame->characters[5 * 20 + 0]);
    }

    #[Test]
    public function verticalMixedExactAndFlex(): void
    {
        $header = Text::new('Header');
        $body = $this->createFlexWidget('B');

        $layout = Layout::vertical($header, $body);
        $frame = new Frame(20, 10);
        $layout->render($frame, $frame->area(), new RenderContext(Theme::dark()));

        self::assertSame('H', $frame->characters[0 * 20 + 0]);
        self::assertSame('B', $frame->characters[1 * 20 + 0]);
    }

    #[Test]
    public function horizontalSplitsWidth(): void
    {
        $left = $this->createFlexWidget('L');
        $right = $this->createFlexWidget('R');

        $layout = Layout::horizontal($left, $right);
        $frame = new Frame(20, 5);
        $layout->render($frame, $frame->area(), new RenderContext(Theme::dark()));

        self::assertSame('L', $frame->characters[0]);
        self::assertSame('R', $frame->characters[10]);
    }

    #[Test]
    public function directionalChildrenReceiveTheParentMainAxis(): void
    {
        $widget = new class implements Widget, DirectionalConstraint {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function constraintFor(Direction $direction): Constraint
            {
                return $direction === Direction::Horizontal
                    ? Constraint::exact(7)
                    : Constraint::exact(3);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };

        $horizontal = Layout::horizontal($widget)->computeChildRects(Rect::fromSize(20, 10));
        $vertical = Layout::vertical($widget)->computeChildRects(Rect::fromSize(20, 10));

        self::assertSame(7, $horizontal[0][1]->width);
        self::assertSame(3, $vertical[0][1]->height);
        self::assertSame(3, Layout::horizontal($widget)->measureHeight(20));
    }

    #[Test]
    public function nestedLayouts(): void
    {
        $layout = Layout::vertical(
            Text::new('Top'),
            Layout::horizontal(
                $this->createFlexWidget('L'),
                $this->createFlexWidget('R'),
            ),
        );

        $frame = new Frame(20, 10);
        $layout->render($frame, $frame->area(), new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[0 * 20 + 0]);
        self::assertSame('L', $frame->characters[1 * 20 + 0]);
        self::assertSame('R', $frame->characters[1 * 20 + 10]);
    }

    #[Test]
    public function layoutIsWidget(): void
    {
        $layout = Layout::vertical(Text::new('test'));
        self::assertInstanceOf(Widget::class, $layout);
    }

    #[Test]
    public function layoutConstraintIsFlex(): void
    {
        $layout = Layout::vertical(Text::new('test'));
        self::assertSame(1, $layout->constraint()->flex);
    }

    #[Test]
    public function flexRemainderDistributedToLastFlexChild(): void
    {
        $capturedAreas = [];
        $makeSpy = function () use (&$capturedAreas): Widget {
            return new class($capturedAreas) implements Widget {
                public function __construct(private array &$areas)
                {
                }

                public function constraint(): Constraint
                {
                    return Constraint::flex();
                }

                public function render(Frame $frame, Rect $area, RenderContext $context): void
                {
                    $this->areas[] = $area;
                }
            };
        };

        $layout = Layout::vertical($makeSpy(), $makeSpy(), $makeSpy());
        $frame = new Frame(20, 10);
        $layout->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        $totalHeight = 0;
        foreach ($capturedAreas as $area) {
            $totalHeight += $area->height;
        }

        self::assertSame(10, $totalHeight, 'All vertical space must be distributed — no remainder lost');
        self::assertSame(4, $capturedAreas[2]->height, 'Last flex child should absorb the remainder pixel');
    }

    #[Test]
    public function horizontalFlexRemainderDistributedToLastFlexChild(): void
    {
        $capturedAreas = [];
        $makeSpy = function () use (&$capturedAreas): Widget {
            return new class($capturedAreas) implements Widget {
                public function __construct(private array &$areas)
                {
                }

                public function constraint(): Constraint
                {
                    return Constraint::flex();
                }

                public function render(Frame $frame, Rect $area, RenderContext $context): void
                {
                    $this->areas[] = $area;
                }
            };
        };

        $layout = Layout::horizontal($makeSpy(), $makeSpy(), $makeSpy());
        $frame = new Frame(10, 5);
        $layout->render($frame, new Rect(0, 0, 10, 5), new RenderContext(Theme::dark()));

        $totalWidth = 0;
        foreach ($capturedAreas as $area) {
            $totalWidth += $area->width;
        }

        self::assertSame(10, $totalWidth, 'All horizontal space must be distributed — no remainder lost');
        self::assertSame(4, $capturedAreas[2]->width, 'Last flex child should absorb the remainder pixel');
    }

    #[Test]
    public function verticalWithZeroHeight(): void
    {
        $frame = new Frame(10, 1);
        $layout = Layout::vertical(Layout::vertical());
        $layout->render($frame, new Rect(0, 0, 10, 0), new RenderContext(Theme::dark()));
        self::assertTrue(true);
    }

    #[Test]
    public function horizontalWithZeroWidth(): void
    {
        $frame = new Frame(1, 10);
        $layout = Layout::horizontal(Layout::horizontal());
        $layout->render($frame, new Rect(0, 0, 0, 10), new RenderContext(Theme::dark()));
        self::assertTrue(true);
    }

    #[Test]
    public function computeChildRectsVertical(): void
    {
        $a = Text::new('A');
        $b = $this->createFlexWidget('B');
        $layout = Layout::vertical($a, $b);

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertCount(2, $rects);
        self::assertSame($a, $rects[0][0]);
        self::assertSame(0, $rects[0][1]->y);
        self::assertSame(1, $rects[0][1]->height);
        self::assertSame($b, $rects[1][0]);
        self::assertSame(1, $rects[1][1]->y);
        self::assertSame(9, $rects[1][1]->height);
    }

    #[Test]
    public function computeChildRectsHorizontal(): void
    {
        $l = $this->createFlexWidget('L');
        $r = $this->createFlexWidget('R');
        $layout = Layout::horizontal($l, $r);

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertCount(2, $rects);
        self::assertSame($l, $rects[0][0]);
        self::assertSame(0, $rects[0][1]->x);
        self::assertSame(10, $rects[0][1]->width);
        self::assertSame($r, $rects[1][0]);
        self::assertSame(10, $rects[1][1]->x);
        self::assertSame(10, $rects[1][1]->width);
    }

    #[Test]
    public function computeChildRectsEmpty(): void
    {
        $layout = Layout::vertical();
        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));
        self::assertCount(0, $rects);
    }

    #[Test]
    public function multipleExactChildrenReservesCumulatively(): void
    {
        $layout = Layout::vertical(
            $this->createExactWidget(3),
            $this->createExactWidget(4),
            $this->createFlexWidget('F'),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(3, $rects[0][1]->height);
        self::assertSame(4, $rects[1][1]->height);
        self::assertSame(3, $rects[2][1]->height, 'Flex child gets totalSpace minus cumulative reserved (10 - 3 - 4 = 3)');
    }

    #[Test]
    public function minConstraintWithoutFlexReservesItsMinimum(): void
    {
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::min(3)),
            $this->createWidgetWithConstraint(Constraint::flex(1)),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(3, $rects[0][1]->height, 'min(3) must reserve 3 rows — a minimum that yields 0 is not a minimum');
        self::assertSame(7, $rects[1][1]->height, 'Flex child gets what remains after the minimum is reserved');
    }

    #[Test]
    public function flexChildIsClampedToItsMaxAndLeftoverFlowsToSiblings(): void
    {
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(new Constraint(min: 0, max: 4, flex: 1)),
            $this->createWidgetWithConstraint(Constraint::flex(1)),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(4, $rects[0][1]->height, 'Flex share (5) must be clamped to max (4)');
        self::assertSame(6, $rects[1][1]->height, 'The clamped-away row must flow to the unclamped flex sibling');
    }

    #[Test]
    public function leftoverSkipsFlexChildrenAlreadyAtTheirMax(): void
    {
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::flex(1)),
            $this->createWidgetWithConstraint(new Constraint(min: 0, max: 4, flex: 1)),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(4, $rects[1][1]->height, 'The clamped last child must never exceed its max, even when leftover exists');
        self::assertSame(6, $rects[0][1]->height, 'Leftover must flow past the clamped child to one with headroom');
    }

    #[Test]
    public function minWithFlexGrowsFromItsMinimum(): void
    {
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(new Constraint(min: 4, flex: 1)),
            $this->createWidgetWithConstraint(Constraint::flex(1)),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(7, $rects[0][1]->height, 'min 4 reserved + equal share of remaining 6 = 7');
        self::assertSame(3, $rects[1][1]->height);
    }

    #[Test]
    public function nonFlexChildAfterFlexDoesNotStealRemainder(): void
    {
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::flex(1)),
            $this->createWidgetWithConstraint(Constraint::flex(1)),
            $this->createWidgetWithConstraint(Constraint::flex(1)),
            $this->createWidgetWithConstraint(new Constraint(min: 0, max: 5, flex: 0)),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(4, $rects[2][1]->height, 'Last flex child absorbs the remainder');
        self::assertSame(0, $rects[3][1]->height, 'Non-flex trailing child must not steal remainder');
    }

    #[Test]
    public function reservedExceedsTotalSpaceClampedToZero(): void
    {
        $layout = Layout::vertical(
            $this->createExactWidget(8),
            $this->createExactWidget(5),
            $this->createFlexWidget('F'),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(0, $rects[2][1]->height, 'Flex child gets 0 when reserved exceeds total space');
    }

    #[Test]
    public function reservedEqualsExactlyTotalSpaceLeavesZeroForFlex(): void
    {
        $layout = Layout::vertical(
            $this->createExactWidget(5),
            $this->createExactWidget(5),
            $this->createFlexWidget('F'),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(0, $rects[2][1]->height, 'Flex child gets 0 when reserved exactly equals total space');
    }

    #[Test]
    public function nonFlexChildBetweenFlexChildrenGetsZero(): void
    {
        $nonExactNonFlex = $this->createWidgetWithConstraint(new Constraint(min: 0, max: 5, flex: 0));
        $flexA = $this->createWidgetWithConstraint(Constraint::flex(1));
        $flexB = $this->createWidgetWithConstraint(Constraint::flex(1));

        $layout = Layout::vertical($flexA, $nonExactNonFlex, $flexB);
        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(5, $rects[0][1]->height);
        self::assertSame(0, $rects[1][1]->height, 'Non-flex child gets zero size');
        self::assertSame(5, $rects[2][1]->height);
    }

    #[Test]
    public function weightedFlexDistributionUsesMultiplication(): void
    {
        $heavy = $this->createWidgetWithConstraint(Constraint::flex(2));
        $light = $this->createWidgetWithConstraint(Constraint::flex(1));

        $layout = Layout::vertical($heavy, $light);
        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 9));

        self::assertSame(6, $rects[0][1]->height, 'Weight 2 of 3 gets 6 out of 9');
        self::assertSame(3, $rects[1][1]->height, 'Weight 1 of 3 gets 3 out of 9');
    }

    #[Test]
    public function singleFlexChildAtIndexZeroGetsAllRemainingSpace(): void
    {
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::flex(1)),
            $this->createExactWidget(3),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(7, $rects[0][1]->height, 'Sole flex child at index 0 gets all remaining space');
        self::assertSame(3, $rects[1][1]->height);
    }

    #[Test]
    public function onlyExactChildrenNoRemainderDistribution(): void
    {
        $layout = Layout::vertical(
            $this->createExactWidget(3),
            $this->createExactWidget(4),
        );

        $rects = $layout->computeChildRects(new Rect(0, 0, 20, 10));

        self::assertSame(3, $rects[0][1]->height);
        self::assertSame(4, $rects[1][1]->height);
    }

    #[Test]
    public function measureHeightReturnsZeroForZeroWidth(): void
    {
        // L43 LessThan: `< 1` → `<= 1`. At width=1, current returns sum/max, mutation returns 0.
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::exact(3)),
            $this->createWidgetWithConstraint(Constraint::exact(2)),
        );

        self::assertSame(0, $layout->measureHeight(0), 'Width 0 must return 0');
        self::assertSame(5, $layout->measureHeight(1), 'Width 1 must still return the sum');
    }

    #[Test]
    public function measureHeightVerticalSumsChildrenHeights(): void
    {
        // L47 Identical: `=== Vertical` → `!== Vertical` swaps vertical/horizontal accumulation.
        // L48 `total = 0` → `total = -1`: changes the starting value.
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::exact(3)),
            $this->createWidgetWithConstraint(Constraint::exact(4)),
            $this->createWidgetWithConstraint(Constraint::exact(2)),
        );

        self::assertSame(9, $layout->measureHeight(20), 'Vertical layout measureHeight must sum children (3+4+2=9)');
    }

    #[Test]
    public function measureHeightHorizontalTakesMaxOfChildren(): void
    {
        // L49 `max = 0` → `max = -1`: changes starting value of max.
        // L47 Identical inverted: vertical becomes horizontal.
        $layout = Layout::horizontal(
            $this->createWidgetWithConstraint(Constraint::exact(3)),
            $this->createWidgetWithConstraint(Constraint::exact(5)),
            $this->createWidgetWithConstraint(Constraint::exact(2)),
        );

        self::assertSame(5, $layout->measureHeight(30), 'Horizontal layout measureHeight must take the max child height (max(3,5,2)=5)');
    }

    #[Test]
    public function measureHeightHorizontalDividesWidthAmongChildrenForMeasurement(): void
    {
        // L53 mutants: per-child width = max(1, intdiv(width, count)) in horizontal layout.
        // Use a deterministic 10-char Paragraph with no spaces (so char-fallback wraps cleanly).
        $paragraph = Paragraph::fromText('abcdefghij');

        $layout = Layout::horizontal(
            $paragraph,
            $this->createWidgetWithConstraint(Constraint::exact(1)),
        );

        // Total width 20 → each child gets 10 → paragraph fits in 1 line.
        self::assertSame(1, $layout->measureHeight(20), 'Per-child width=10 must fit "abcdefghij" in 1 line');
        // Total width 10 → each child gets 5 → paragraph wraps to 2 lines.
        self::assertSame(2, $layout->measureHeight(10), 'Per-child width=5 must wrap "abcdefghij" to 2 lines (kills Ternary swap mutant)');
        // Total width 4 → each child gets 2 → paragraph wraps to 5 lines.
        self::assertSame(5, $layout->measureHeight(4), 'Per-child width=2 must wrap "abcdefghij" to 5 lines (kills width-division mutants)');
        // Total width 3 → intdiv(3,2)=1 → max(1,1)=1 → paragraph at width 1 wraps to 10 lines.
        // With L53 IncrementInteger max(2,...): width=2 → 5 lines. With DecrementInteger max(0,...): width=1 → 10 lines.
        // The IncrementInteger mutant max(2, intdiv(3,2)) = max(2, 1) = 2 → 5 lines (differs from current 10).
        self::assertSame(10, $layout->measureHeight(3), 'Per-child width=1 must wrap to 10 lines (kills max(2,...) IncrementInteger)');
        // Total width 1 → intdiv(1,2)=0 → max(1,0)=1 → paragraph at width 1 → 10 lines.
        // With max(0,0)=0: paragraph at width 0 → 0. max(0, 1) = 1.
        self::assertSame(10, $layout->measureHeight(1), 'Per-child width clamped to 1 when intdiv is 0 (kills max(0,...) DecrementInteger)');
    }

    #[Test]
    public function measureHeightHorizontalWithNoChildrenReturnsZero(): void
    {
        // L49 `max = 0` → `max = -1`: empty horizontal layout. Initial max is returned.
        $layout = Layout::horizontal();
        self::assertSame(0, $layout->measureHeight(20), 'Empty horizontal layout must return 0 (initial max value)');
    }

    #[Test]
    public function constraintHeightUsesMinWhenMinEqualsMax(): void
    {
        // L68 Identical: `min === max` branch returns min.
        // L69 ReturnRemoval: the early return.
        // For constraint exact(3), min == max == 3 — returns 3.
        // With ReturnRemoval, falls through to max(1, 3) = 3. Same result, equivalent.
        // With Identical mutated to !==, takes else branch: max(1, 3) = 3. Same.
        // → Both look equivalent. But let me try with min=0 to differentiate.
        // For Constraint::flex() (min=0, max=PHP_INT_MAX), min != max. Else branch: max(1, 0) = 1.
        // For an unusual case — but typical widgets use exact() or flex().
        // Hmm — the case where they differ: min === max AND min === 0 (e.g., exact(0)).
        // Current: return 0. Mutation (ReturnRemoval): max(1, 0) = 1.
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::exact(0)),
            $this->createWidgetWithConstraint(Constraint::exact(3)),
        );

        self::assertSame(3, $layout->measureHeight(20), 'exact(0) constraint must contribute 0 to the sum, not max(1, 0)=1');
    }

    #[Test]
    public function measureHeightUsesChildConstraintWhenNotMeasureHeight(): void
    {
        // L57 Assignment: `+=` → `=` would lose the accumulation.
        $layout = Layout::vertical(
            $this->createWidgetWithConstraint(Constraint::exact(3)),
            $this->createWidgetWithConstraint(Constraint::exact(4)),
        );

        // measureHeight must sum: 3 + 4 = 7. With Assignment mutant `total = childHeight`,
        // the final value would be just the last child's height (4), not the sum.
        self::assertSame(7, $layout->measureHeight(20), 'Vertical must accumulate (+=), not overwrite');
    }

    #[Test]
    public function bothStackersStopACeilingChildAtTheSameHeight(): void
    {
        $capped = $this->createConstrainedWidget('C', Constraint::max(3));
        $rest = $this->createFlexWidget('R');

        $frame = new Frame(20, 20);
        Layout::vertical($capped, $rest)->render($frame, $frame->area(), new RenderContext(Theme::dark()));

        self::assertSame('C', $frame->characters[0]);
        self::assertSame('R', $frame->characters[3 * 20], 'The capped child gives up everything past its ceiling');
    }

    private function createConstrainedWidget(string $character, Constraint $constraint): Widget
    {
        return new class($character, $constraint) implements Widget {
            public function __construct(
                private readonly string $character,
                private readonly Constraint $constraint,
            ) {
            }

            public function constraint(): Constraint
            {
                return $this->constraint;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putChar($area->x, $area->y, $this->character);
            }
        };
    }

    private function createFlexWidget(string $character): Widget
    {
        return new class($character) implements Widget {
            public function __construct(private readonly string $character)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::flex();
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putChar($area->x, $area->y, $this->character);
            }
        };
    }

    private function createExactWidget(int $size): Widget
    {
        return new class($size) implements Widget {
            public function __construct(private readonly int $size)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact($this->size);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function createWidgetWithConstraint(Constraint $constraint): Widget
    {
        return new class($constraint) implements Widget {
            public function __construct(private readonly Constraint $constraint)
            {
            }

            public function constraint(): Constraint
            {
                return $this->constraint;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
