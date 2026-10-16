<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\LayoutPlan;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Rendering\FloatQueue;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Popup\Floating;
use Elmphant\Tui\Widget\Popup\FloatTarget;
use Elmphant\Tui\Widget\Popup\PointerFloating;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FloatDrainCounter
{
    public int $count = 0;
}

final class RootPassTest extends TestCase
{
    #[Test]
    public function theDrainRecordsEveryPointerFloatItRenderedInPaintOrder(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(40, 10);
        $anchor = new Rect(0, 0, 10, 1);
        $firstPopup = self::pointerFloat('first', new Rect(0, 1, 8, 1));
        $secondPopup = self::pointerFloat('second', new Rect(0, 2, 8, 1));

        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, $firstPopup, $secondPopup),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        $placements = $pass->floats->placements();

        self::assertCount(2, $placements);
        self::assertSame($firstPopup, $placements[0]->float);
        self::assertSame($anchor, $placements[0]->anchor);
        self::assertSame($firstPopup->target(), $placements[0]->target());
        self::assertSame($secondPopup, $placements[1]->float);
        self::assertSame($anchor, $placements[1]->anchor);
        self::assertSame($secondPopup->target(), $placements[1]->target());
    }

    #[Test]
    public function aPlainFloatingDrawsButIsNeverRecorded(): void
    {
        $pass = self::passWithOnePublishedFloat();
        $frame = new Frame(10, 5);

        $pass->begin(0.0);
        RootPass::draw($pass, self::widgetQueuingPlacement(new Rect(0, 0, 3, 1)), $frame, $frame->area());
        $pass->floats->commit();

        self::assertStringContainsString('X', ScreenBuffer::fromFrame($frame)->plainText());
        self::assertSame([], $pass->floats->placements());
    }

    #[Test]
    public function aFloatSkippedForZeroSizeIsNotRecorded(): void
    {
        $pass = self::passWithOnePublishedFloat();
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 10, 1);

        $pass->begin(0.0);
        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, self::pointerFloat('zero', new Rect(0, 0, 0, 3))),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        self::assertSame([], $pass->floats->placements());
    }

    #[Test]
    public function aStagedFrameIsOnlyRoutableOnceItIsCommitted(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(20, 5);
        $anchor = new Rect(0, 0, 5, 1);
        $published = self::pointerFloat('published', new Rect(0, 1, 5, 1));
        $staged = self::pointerFloat('staged', new Rect(0, 2, 5, 1));

        RootPass::draw($pass, self::widgetQueuingPointerFloats($anchor, $published), $frame, $frame->area());
        $pass->floats->commit();

        $pass->begin(0.0);
        RootPass::draw($pass, self::widgetQueuingPointerFloats($anchor, $staged), $frame, $frame->area());

        self::assertSame($published, $pass->floats->placements()[0]->float, 'a frame that was never committed must not be routable');

        $pass->floats->commit();

        self::assertSame($staged, $pass->floats->placements()[0]->float);
    }

    #[Test]
    public function aRootPassThatThrowsMidDrainStagesNothingToCommit(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);

        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, self::pointerFloat('kept', $anchor)),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();
        $committedBeforeTheThrow = $pass->floats->placements();

        $pass->begin(0.0);

        try {
            RootPass::draw($pass,
                self::widgetQueuingPointerFloats(
                    $anchor,
                    self::pointerFloat('drawn before the throw', $anchor),
                    self::throwingPointerFloat($anchor),
                ),
                $frame,
                $frame->area(),
            );
            self::fail('the root pass should have propagated the exception');
        } catch (RuntimeException) {
        }

        self::assertSame($committedBeforeTheThrow, $pass->floats->placements(), 'the published list is untouched');

        $pass->floats->commit();

        self::assertSame(
            [],
            $pass->floats->placements(),
            'a half-drawn frame stages nothing — not even the floats it managed to draw',
        );
    }

    #[Test]
    public function aCommittedFloatlessFramePublishesTheEmptyList(): void
    {
        $pass = self::passWithOnePublishedFloat();
        $frame = new Frame(10, 5);

        $pass->begin(0.0);
        RootPass::draw($pass, self::emptyWidget(), $frame, $frame->area());
        $pass->floats->commit();

        self::assertSame([], $pass->floats->placements());
    }

    #[Test]
    public function beginningAFrameDropsACandidateThatWasNeverCommitted(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);

        $pass->begin(0.0);
        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, self::pointerFloat('never presented', $anchor)),
            $frame,
            $frame->area(),
        );

        $pass->begin(1.0);
        self::emptyWidget()->render($frame, $frame->area(), $pass->context);
        $pass->floats->commit();

        self::assertSame(
            [],
            $pass->floats->placements(),
            'a frame that renders without a root pass must not republish an earlier candidate',
        );
    }

    #[Test]
    public function aFloatQueuedDuringTheDrainDrawsNextIterationAndRecordsAfter(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(20, 5);
        $anchor = new Rect(0, 0, 5, 1);
        $firstPlacement = new Rect(0, 0, 5, 1);
        $secondPlacement = new Rect(0, 1, 5, 1);

        $second = self::pointerFloat('second', $secondPlacement);
        $first = self::queuingPointerFloat('first', $firstPlacement, $second, $anchor);

        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, $first),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        $placements = $pass->floats->placements();

        self::assertCount(2, $placements);
        self::assertSame($first->target(), $placements[0]->target());
        self::assertSame($second->target(), $placements[1]->target());
    }

    #[Test]
    public function floatsStillQueuedAfterThreeIterationsAreDropped(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(20, 5);
        $anchor = new Rect(0, 0, 5, 1);
        $renders = new FloatDrainCounter();

        RootPass::draw($pass,
            self::widgetQueuingSelfRequeuingFloat($anchor, $renders),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        self::assertSame(3, $renders->count, 'a float that keeps re-queuing itself draws for at most 3 iterations');
        self::assertCount(3, $pass->floats->placements());
    }

    #[Test]
    public function aRootPassUnderALayoutPlanStillDrains(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);
        $placementCalls = new FloatDrainCounter();
        $float = self::pointerFloat('planned', $anchor);
        $root = self::containerQueuingPointerFloat($anchor, $float, $placementCalls);
        $plan = LayoutPlan::resolve($root, $frame->area());

        RootPass::draw($pass, $root, $frame, $frame->area(), $plan);
        $pass->floats->commit();

        self::assertSame(1, $placementCalls->count, 'the root pass must reuse the plan instead of recomputing layout');
        self::assertCount(1, $pass->floats->placements());
        self::assertSame($float->target(), $pass->floats->placements()[0]->target());
    }

    #[Test]
    public function aFloatDrawsOutsideTheClipTheTreeRenderedUnder(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(40, 10);

        RootPass::draw($pass, self::host('POPUP', new Rect(0, 1, 40, 2)), $frame, $frame->area());

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertStringContainsString('base', $screen->lineAt(1), 'The tree still drew');
        self::assertStringContainsString('POPUP', $screen->lineAt(4), 'and the float escaped the clip');
    }

    #[Test]
    public function aRootPassResetsAnyFloatQueuedBeforeItRuns(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(40, 10);
        $pass->context->float($frame, self::labelled('STALE'), new Rect(0, 0, 10, 1));

        RootPass::draw($pass, self::emptyWidget(), $frame, $frame->area());

        self::assertStringNotContainsString(
            'STALE',
            ScreenBuffer::fromFrame($frame)->plainText(),
            'A float left over from before this call must not survive into this render',
        );
        self::assertSame([], $pass->floats->queued(), 'and it is discarded, never restored afterwards');
    }

    #[Test]
    public function aThrowDuringTheDrainStillClearsTheQueue(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);

        try {
            RootPass::draw($pass,
                self::widgetQueuingPointerFloats($anchor, self::throwingPointerFloat($anchor)),
                $frame,
                $frame->area(),
            );
            self::fail('the root pass should have propagated the exception');
        } catch (RuntimeException) {
        }

        self::assertSame([], $pass->floats->queued());
    }

    #[Test]
    public function committingFromInsideARootPassThrows(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Float placements cannot be committed inside a root pass; the runtime commits once the frame is on the screen.');

        RootPass::draw($pass, self::widgetCommittingMidRender($pass->floats), $frame, $frame->area());
    }

    #[Test]
    public function aNestedRootPassThrows(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A root pass cannot be nested inside another root pass.');

        RootPass::draw($pass, self::widgetStartingAnotherRootPass($pass), $frame, $frame->area());
    }

    #[Test]
    public function aSecondRootPassInOneFrameThrows(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        $pass->begin(0.0);
        RootPass::draw($pass, self::emptyWidget(), $frame, $frame->area());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A root pass may run only once per frame; compose a multi-part screen under one root.');

        RootPass::draw($pass, self::emptyWidget(), $frame, $frame->area());
    }

    #[Test]
    public function beginningTheNextFrameAllowsTheNextRootPass(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);
        $nextFrameFloat = self::pointerFloat('next frame', $anchor);

        $pass->begin(0.0);
        RootPass::draw($pass, self::emptyWidget(), $frame, $frame->area());

        $pass->begin(1.0);
        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, $nextFrameFloat),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        self::assertSame($nextFrameFloat, $pass->floats->placements()[0]->float);
    }

    #[Test]
    public function aFloatQueuedAgainstAForeignFrameIsIgnoredWhileARootIsActive(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);
        $legit = self::pointerFloat('legit', $anchor);
        $foreign = self::pointerFloat('foreign', $anchor);

        RootPass::draw($pass,
            self::widgetQueuingOnRealAndForeignFrames($anchor, $legit, $foreign),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        $placements = $pass->floats->placements();

        self::assertCount(1, $placements);
        self::assertSame($legit->target(), $placements[0]->target());
    }

    #[Test]
    public function aPlacementExactlyOneCellWideStillRenders(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        RootPass::draw($pass, self::widgetQueuingPlacement(new Rect(0, 0, 1, 1)), $frame, $frame->area());

        self::assertStringContainsString('X', ScreenBuffer::fromFrame($frame)->plainText());
    }

    #[Test]
    public function aZeroWidthPlacementIsSkippedEvenWhenTheHeightIsValid(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        RootPass::draw($pass, self::widgetQueuingPlacement(new Rect(0, 0, 0, 3)), $frame, $frame->area());

        self::assertStringNotContainsString('X', ScreenBuffer::fromFrame($frame)->plainText());
    }

    #[Test]
    public function aZeroHeightPlacementIsSkippedEvenWhenTheWidthIsValid(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        RootPass::draw($pass, self::widgetQueuingPlacement(new Rect(0, 0, 3, 0)), $frame, $frame->area());

        self::assertStringNotContainsString('X', ScreenBuffer::fromFrame($frame)->plainText());
    }

    #[Test]
    public function aSkippedFloatDoesNotStopLaterFloatsFromDrawing(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);

        RootPass::draw($pass,
            self::widgetQueuingTwoPlacements(new Rect(0, 0, 0, 3), new Rect(0, 0, 3, 1)),
            $frame,
            $frame->area(),
        );

        self::assertStringContainsString('X', ScreenBuffer::fromFrame($frame)->plainText());
    }

    #[Test]
    public function theLastFloatQueuedDrawsOverTheOnesBeforeIt(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(40, 10);
        $anchor = new Rect(0, 0, 10, 1);

        RootPass::draw($pass,
            self::twoFloats($anchor),
            $frame,
            $frame->area(),
        );

        self::assertStringContainsString('second', ScreenBuffer::fromFrame($frame)->lineAt(3));
    }

    /**
     * A pass whose published list already holds one placement, so a later
     * frame asserting an EMPTY list has something to overwrite.
     */
    private static function passWithOnePublishedFloat(): RenderPass
    {
        $pass = RenderPass::new(Theme::dark());
        $frame = new Frame(10, 5);
        $anchor = new Rect(0, 0, 5, 1);

        $pass->begin(0.0);
        RootPass::draw($pass,
            self::widgetQueuingPointerFloats($anchor, self::pointerFloat('published', $anchor)),
            $frame,
            $frame->area(),
        );
        $pass->floats->commit();

        return $pass;
    }

    private static function widgetCommittingMidRender(FloatQueue $floats): Widget
    {
        return new class($floats) implements Widget {
            public function __construct(private readonly FloatQueue $floats)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $this->floats->commit();
            }
        };
    }

    private static function emptyWidget(): Widget
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

    private static function widgetQueuingPlacement(Rect $placement): Widget
    {
        return new class($placement) implements Widget {
            public function __construct(private readonly Rect $placement)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, self::fixedAt($this->placement), new Rect(0, 0, 5, 1));
            }

            private static function fixedAt(Rect $placement): Floating
            {
                return new class($placement) implements Floating {
                    public function __construct(private readonly Rect $placement)
                    {
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return $this->placement;
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, 'X');
                    }
                };
            }
        };
    }

    private static function widgetQueuingTwoPlacements(Rect $first, Rect $second): Widget
    {
        return new class($first, $second) implements Widget {
            public function __construct(
                private readonly Rect $first,
                private readonly Rect $second,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $anchor = new Rect(0, 0, 5, 1);
                $context->float($frame, self::fixedAt($this->first, 'SKIPPED'), $anchor);
                $context->float($frame, self::fixedAt($this->second, 'X'), $anchor);
            }

            private static function fixedAt(Rect $placement, string $text): Floating
            {
                return new class($placement, $text) implements Floating {
                    public function __construct(
                        private readonly Rect $placement,
                        private readonly string $text,
                    ) {
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return $this->placement;
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, $this->text);
                    }
                };
            }
        };
    }

    private static function labelled(string $text): Floating
    {
        return new class($text) implements Floating {
            public function __construct(private readonly string $text)
            {
            }

            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return new Rect($anchor->x, $anchor->y + 3, 10, 1);
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->text);
            }
        };
    }

    private static function twoFloats(Rect $anchor): Widget
    {
        return new class($anchor) implements Widget {
            public function __construct(private readonly Rect $anchor)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, self::labelled('first '), $this->anchor);
                $context->float($frame, self::labelled('second'), $this->anchor);
            }

            private static function labelled(string $text): Floating
            {
                return new class($text) implements Floating {
                    public function __construct(private readonly string $text)
                    {
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return new Rect($anchor->x, $anchor->y + 3, 10, 1);
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, $this->text);
                    }
                };
            }
        };
    }

    private static function widgetQueuingPointerFloats(Rect $anchor, PointerFloating ...$floats): Widget
    {
        return new class($anchor, $floats) implements Widget {
            /**
             * @param list<PointerFloating> $floats
             */
            public function __construct(
                private readonly Rect $anchor,
                private readonly array $floats,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                foreach ($this->floats as $float) {
                    $context->float($frame, $float, $this->anchor);
                }
            }
        };
    }

    private static function widgetQueuingOnRealAndForeignFrames(
        Rect $anchor,
        PointerFloating $legit,
        PointerFloating $foreign,
    ): Widget {
        return new class($anchor, $legit, $foreign) implements Widget {
            public function __construct(
                private readonly Rect $anchor,
                private readonly PointerFloating $legit,
                private readonly PointerFloating $foreign,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $scratch = new Frame(5, 1);
                $context->float($scratch, $this->foreign, $this->anchor);
                $context->float($frame, $this->legit, $this->anchor);
            }
        };
    }

    private static function widgetStartingAnotherRootPass(RenderPass $pass): Widget
    {
        $inner = self::emptyWidget();

        return new class($pass, $inner) implements Widget {
            public function __construct(
                private readonly RenderPass $pass,
                private readonly Widget $inner,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                RootPass::draw($this->pass, $this->inner, $frame, $area);
            }
        };
    }

    private static function containerQueuingPointerFloat(
        Rect $anchor,
        PointerFloating $float,
        FloatDrainCounter $placementCalls,
    ): Widget {
        $child = self::widgetQueuingPointerFloats($anchor, $float);

        return new class($child, $placementCalls) implements Widget, WidgetContainer {
            public function __construct(
                private readonly Widget $child,
                private readonly FloatDrainCounter $placementCalls,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            /**
             * @return iterable<Widget>
             */
            public function children(): iterable
            {
                return [$this->child];
            }

            /**
             * @return iterable<array{0: Widget, 1: Rect}>
             */
            public function placedChildren(Rect $area): iterable
            {
                ++$this->placementCalls->count;

                return [[$this->child, $area]];
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
                    $child->render($frame, $childArea, $context);
                }
            }
        };
    }

    private static function pointerFloat(string $label, Rect $placement): PointerFloating
    {
        return new class($label, $placement) implements PointerFloating {
            private readonly FloatTarget $target;

            public function __construct(
                private readonly string $label,
                private readonly Rect $placement,
            ) {
                $this->target = new FloatTarget();
            }

            public function target(): FloatTarget
            {
                return $this->target;
            }

            public function captures(MouseAction $action): bool
            {
                return false;
            }

            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $this->placement;
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->label);
            }
        };
    }

    private static function throwingPointerFloat(Rect $placement): PointerFloating
    {
        return new class($placement) implements PointerFloating {
            private readonly FloatTarget $target;

            public function __construct(private readonly Rect $placement)
            {
                $this->target = new FloatTarget();
            }

            public function target(): FloatTarget
            {
                return $this->target;
            }

            public function captures(MouseAction $action): bool
            {
                return false;
            }

            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $this->placement;
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, $this, $this->placement);

                throw new RuntimeException('render blew up');
            }
        };
    }

    private static function queuingPointerFloat(
        string $label,
        Rect $placement,
        ?PointerFloating $toQueue,
        ?Rect $queueAnchor,
    ): PointerFloating {
        return new class($label, $placement, $toQueue, $queueAnchor) implements PointerFloating {
            private readonly FloatTarget $target;

            public function __construct(
                private readonly string $label,
                private readonly Rect $placement,
                private readonly ?PointerFloating $toQueue,
                private readonly ?Rect $queueAnchor,
            ) {
                $this->target = new FloatTarget();
            }

            public function target(): FloatTarget
            {
                return $this->target;
            }

            public function captures(MouseAction $action): bool
            {
                return false;
            }

            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $this->placement;
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->label);

                if ($this->toQueue !== null && $this->queueAnchor !== null) {
                    $context->float($frame, $this->toQueue, $this->queueAnchor);
                }
            }
        };
    }

    private static function widgetQueuingSelfRequeuingFloat(Rect $anchor, FloatDrainCounter $renders): Widget
    {
        return new class($anchor, $renders) implements Widget {
            public function __construct(
                private readonly Rect $anchor,
                private readonly FloatDrainCounter $renders,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $context->float($frame, self::selfRequeuingFloat($this->anchor, $this->renders), $this->anchor);
            }

            private static function selfRequeuingFloat(Rect $anchor, FloatDrainCounter $renders): PointerFloating
            {
                return new class($anchor, $renders) implements PointerFloating {
                    private readonly FloatTarget $target;

                    public function __construct(
                        private readonly Rect $anchor,
                        private readonly FloatDrainCounter $renders,
                    ) {
                        $this->target = new FloatTarget();
                    }

                    public function target(): FloatTarget
                    {
                        return $this->target;
                    }

                    public function captures(MouseAction $action): bool
                    {
                        return false;
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return $this->anchor;
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        ++$this->renders->count;
                        $context->float($frame, $this, $this->anchor);
                    }
                };
            }
        };
    }

    /**
     * A widget that clips itself to two rows, draws inside, and floats a
     * popup four rows down — outside its own clip.
     */
    private static function host(string $text, Rect $clip): Widget
    {
        return new class($text, $clip) implements Widget {
            public function __construct(
                private readonly string $text,
                private readonly Rect $clip,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->pushClip($this->clip);

                try {
                    $frame->putString($area->x, $this->clip->y, 'base');
                    $context->float($frame, self::popup($this->text), new Rect(0, $this->clip->y, 10, 1));
                } finally {
                    $frame->popClip();
                }
            }

            private static function popup(string $text): Floating
            {
                return new class($text) implements Floating {
                    public function __construct(private readonly string $text)
                    {
                    }

                    public function placement(Rect $anchor, Rect $viewport): Rect
                    {
                        return new Rect($anchor->x, $anchor->y + 3, 10, 1);
                    }

                    public function sizing(): Sizing
                    {
                        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
                    }

                    public function render(Frame $frame, Rect $area, RenderContext $context): void
                    {
                        $frame->putString($area->x, $area->y, $this->text);
                    }
                };
            }
        };
    }
}
