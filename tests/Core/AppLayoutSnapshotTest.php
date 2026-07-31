<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\LayoutSnapshot;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Core\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Panel;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ViewCallCounter
{
    public static int $count = 0;
}

final class ComponentsCallCounter
{
    public static int $count = 0;
}

final class BindingsCallCounter
{
    public static int $count = 0;
}

final class ChildLayoutCounter
{
    public int $count = 0;
}

final readonly class SnapshotCountingApp extends CopyableTestApp
{
    public function __construct(
        public Panel $panel,
    ) {
    }

    public function view(): Widget
    {
        ++ViewCallCounter::$count;
        ++ComponentsCallCounter::$count;

        return Layout::vertical(
            Component::bind(
                ComponentId::named('main-panel'),
                $this->panel,
                static fn (self $app, Panel $panel): self => $app->cloneWith(panel: $panel),
            ),
            Text::new('footer'),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        ++BindingsCallCounter::$count;

        return Keybindings::empty();
    }
}

final readonly class QuitBindingsCountingApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Text::new('static');
    }

    protected function quitBindings(): Keybindings
    {
        ++BindingsCallCounter::$count;

        return new Keybindings(['quit' => ['q']]);
    }
}

final readonly class LayoutPlanCountingApp extends CopyableTestApp
{
    public function __construct(
        public Panel $panel,
        public ChildLayoutCounter $layouts,
    ) {
    }

    public function view(): Widget
    {
        return new CountingWidgetContainer(
            Component::bind(
                ComponentId::named('counted-panel'),
                $this->panel,
                static fn (self $app, Panel $panel): self => $app->cloneWith(panel: $panel),
            ),
            $this->layouts,
        );
    }
}

final readonly class CountingWidgetContainer implements Widget, WidgetContainer
{
    public function __construct(
        private Widget $child,
        private ChildLayoutCounter $layouts,
    ) {
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
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
    public function childWidgets(Rect $area): array
    {
        ++$this->layouts->count;

        return [[$this->child, $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($context->childrenOf($this, $area) as [$child, $childArea]) {
            $child->render($frame, $childArea, $context);
        }
    }
}

final class AppLayoutSnapshotTest extends TestCase
{
    #[Test]
    public function repeatedUpdatesBuildTheComponentRegistryOncePerAppValue(): void
    {
        $app = new SnapshotCountingApp(Panel::new('Main')->withFocus(true));
        $screenRect = Rect::fromSize(80, 24);

        $app->update(new KeyMsg('x'), $screenRect);
        $app->update(new KeyMsg('y'), $screenRect);

        self::assertSame(1, ComponentsCallCounter::$count);
    }

    #[Test]
    public function aDistinctAppValueGetsItsOwnComponentRegistry(): void
    {
        $screenRect = Rect::fromSize(80, 24);

        (new SnapshotCountingApp(Panel::new('First')->withFocus(true)))
            ->update(new KeyMsg('x'), $screenRect);
        (new SnapshotCountingApp(Panel::new('Second')->withFocus(true)))
            ->update(new KeyMsg('x'), $screenRect);

        self::assertSame(2, ComponentsCallCounter::$count);
    }

    #[Test]
    public function repeatedChildDispatchReusesTheDispatcherAndGlobalBindings(): void
    {
        $app = new SnapshotCountingApp(Panel::new('Main')->withFocus(true));
        $screenRect = Rect::fromSize(80, 24);

        $app->update(new KeyMsg('x'), $screenRect);
        $app->update(new KeyMsg('y'), $screenRect);

        self::assertSame(1, BindingsCallCounter::$count);
    }

    #[Test]
    public function repeatedKeysReuseParsedQuitBindings(): void
    {
        $app = new QuitBindingsCountingApp();
        $screenRect = Rect::fromSize(80, 24);

        $app->update(new KeyMsg('x'), $screenRect);
        $app->update(new KeyMsg('y'), $screenRect);

        self::assertSame(1, BindingsCallCounter::$count);
    }

    #[Test]
    public function repeatedNoOpKeyDispatchResolvesLayoutOnce(): void
    {
        $app = new SnapshotCountingApp(Panel::new('Main')->withFocus(true));
        $screenRect = Rect::fromSize(80, 24);

        $first = $app->update(new KeyMsg('x'), $screenRect);
        self::assertSame($app, $first->model, 'Precondition: an ignored key must not produce a new app instance');

        $second = $app->update(new KeyMsg('x'), $screenRect);
        self::assertSame($app, $second->model);

        self::assertSame(1, ViewCallCounter::$count, 'Layout for the same app instance and screen must be resolved once, not per message');
    }

    #[Test]
    public function resizeReusesTheViewTreeWhileRecomputingLayoutForTheNewArea(): void
    {
        $app = new SnapshotCountingApp(Panel::new('Main')->withFocus(true));

        $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        $app->update(new KeyMsg('x'), Rect::fromSize(120, 40));

        self::assertSame(1, ViewCallCounter::$count, 'An immutable app value must build its screen-independent view tree once');
    }

    #[Test]
    public function routingAndRenderingShareOneResolvedLayoutPlan(): void
    {
        $layouts = new ChildLayoutCounter();
        $app = new LayoutPlanCountingApp(
            Panel::new('Main')->withFocus(true),
            $layouts,
        );
        $screen = Rect::fromSize(80, 24);

        $result = $app->update(new KeyMsg('ignored'), $screen);
        self::assertSame($app, $result->model);

        $frame = new Frame(80, 24);
        $app->render($frame, new RenderContext(Theme::dark()));
        $app->render(new Frame(80, 24), new RenderContext(Theme::dark()));

        self::assertSame(1, $layouts->count);
        self::assertNotSame(' ', $frame->characters[0], 'A reused plan must still render the widget tree');
    }

    #[Test]
    public function renderOnlyDoesNotMaterializeARoutingPlan(): void
    {
        $layouts = new ChildLayoutCounter();
        $app = new LayoutPlanCountingApp(Panel::new('Main'), $layouts);
        $area = Rect::fromSize(80, 24);

        $app->render(new Frame(80, 24), new RenderContext(Theme::dark()));

        self::assertSame(1, $layouts->count);
        self::assertNull(LayoutSnapshot::existingPlanFor($app, $area));
    }

    #[Test]
    public function layoutPlanFactoryRemainsAvailableToAppRendering(): void
    {
        self::assertTrue((new ReflectionMethod(LayoutSnapshot::class, 'planFor'))->isPublic());
    }

    protected function setUp(): void
    {
        ViewCallCounter::$count = 0;
        ComponentsCallCounter::$count = 0;
        BindingsCallCounter::$count = 0;
    }
}
