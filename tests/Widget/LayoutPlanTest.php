<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LayoutPlanTest extends TestCase
{
    #[Test]
    public function reusedContainerIdentityKeepsDistinctPlacementsForEachArea(): void
    {
        $counter = new LayoutPlanCounter();
        $container = new LayoutPlanContainer(Text::new('child'), $counter);
        $root = Layout::horizontal($container, $container);
        $area = Rect::fromSize(10, 4);

        $plan = LayoutPlan::resolve($root, $area);

        self::assertSame(2, $counter->count);
        $left = $plan->childrenOf($container, new Rect(0, 0, 5, 4))[0][1] ?? null;
        $right = $plan->childrenOf($container, new Rect(5, 0, 5, 4))[0][1] ?? null;
        self::assertInstanceOf(Rect::class, $left);
        self::assertInstanceOf(Rect::class, $right);
        self::assertTrue($left->equals(new Rect(0, 0, 5, 4)));
        self::assertTrue($right->equals(new Rect(5, 0, 5, 4)));

        $frame = new Frame(10, 4);
        $pass = RenderPass::new(Theme::dark());
        $pass->layout->during($plan, static fn () => $root->render($frame, $area, $pass->context));

        self::assertSame(2, $counter->count, 'Rendering must reuse both planned placements');
        self::assertSame('child', implode('', array_slice($frame->characters, 0, 5)));
        self::assertSame('child', implode('', array_slice($frame->characters, 5, 5)));
    }

    #[Test]
    public function renderContextFallsBackToContainerLayoutWithoutAnActivePlan(): void
    {
        $counter = new LayoutPlanCounter();
        $container = new LayoutPlanContainer(Text::new('child'), $counter);
        $area = Rect::fromSize(10, 4);

        $container->render(new Frame(10, 4), $area, new RenderContext(Theme::dark()));

        self::assertSame(1, $counter->count);
    }

    #[Test]
    public function plannedChildrenQueryDoesNotTriggerFallbackLayout(): void
    {
        $counter = new LayoutPlanCounter();
        $container = new LayoutPlanContainer(Text::new('child'), $counter);
        $context = new RenderContext(Theme::dark());

        self::assertNull($context->plannedChildrenOf($container, Rect::fromSize(10, 4)));
        self::assertSame(0, $counter->count);
    }

    #[Test]
    public function plannedChildrenQueryReturnsTheActivePlanWithoutRecomputingLayout(): void
    {
        $counter = new LayoutPlanCounter();
        $inspection = new LayoutPlanInspection();
        $container = new InspectingLayoutPlanContainer(Text::new('child'), $counter, $inspection);
        $area = Rect::fromSize(10, 4);
        $plan = LayoutPlan::resolve($container, $area);

        $pass = RenderPass::new(Theme::dark());
        $pass->layout->during($plan, static fn () => $container->render(new Frame(10, 4), $area, $pass->context));

        self::assertSame(1, $counter->count);
        self::assertCount(1, $inspection->children ?? []);
        self::assertSame($container->children()[0], $inspection->children[0][0] ?? null);
    }

    #[Test]
    public function theLayoutInForceRestoresItsPreviousPlanAfterRenderFailure(): void
    {
        $counter = new LayoutPlanCounter();
        $container = new LayoutPlanContainer(new ThrowingLayoutWidget(), $counter);
        $area = Rect::fromSize(10, 4);
        $plan = LayoutPlan::resolve($container, $area);
        $pass = RenderPass::new(Theme::dark());

        try {
            $pass->layout->during($plan, static fn () => $container->render(new Frame(10, 4), $area, $pass->context));
            self::fail('Precondition: child rendering must fail');
        } catch (RuntimeException) {
        }

        foreach (PlacedChildren::of($container, $area, $pass->context) as $_) {
            // Consume a potential lazy container implementation.
        }

        self::assertSame(2, $counter->count);
    }

    #[Test]
    public function aComponentMountedBothBehindAndInsideAModalIsRefused(): void
    {
        $field = Component::bind(
            'field',
            TextInput::new(),
            static fn (App $app, TextInput $input): App => $app,
        );
        $root = Overlay::new($field, Modal::new('Edit', $field));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Widget "field" is mounted more than once');

        LayoutPlan::resolve($root, Rect::fromSize(40, 20));
    }
}

final class LayoutPlanCounter
{
    public int $count = 0;
}

final class LayoutPlanInspection
{
    /**
     * @var list<array{Widget, Rect}>|null
     */
    public ?array $children = null;
}

final readonly class LayoutPlanContainer implements Widget, WidgetContainer
{
    public function __construct(
        private Widget $child,
        private LayoutPlanCounter $counter,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [$this->child];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
    {
        ++$this->counter->count;

        return [[$this->child, $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
            $child->render($frame, $childArea, $context);
        }
    }
}

final readonly class ThrowingLayoutWidget implements Widget
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        throw new RuntimeException('render failed');
    }
}

final readonly class InspectingLayoutPlanContainer implements Widget, WidgetContainer
{
    public function __construct(
        private Widget $child,
        private LayoutPlanCounter $counter,
        private LayoutPlanInspection $inspection,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [$this->child];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
    {
        ++$this->counter->count;

        return [[$this->child, $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->inspection->children = $context->plannedChildrenOf($this, $area);
    }
}
