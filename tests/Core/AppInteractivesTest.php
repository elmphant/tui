<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Tests\Core\Support\CopyableTestApp;
use Elmphant\Tui\Tests\Support\TickTally;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class FooterOnlyApp extends CopyableTestApp
{
    public function __construct(
        public Footer $footer,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Text::new('content'),
            Component::bind(
                ComponentId::named('status-footer'),
                $this->footer,
                static fn (self $app, Footer $footer): self => $app->cloneWith(footer: $footer),
            ),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::tick(0.08);
    }
}

final readonly class PanelAndFooterApp extends CopyableTestApp
{
    public function __construct(
        public Panel $panel,
        public Footer $footer,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                ComponentId::named('primary-panel'),
                $this->panel,
                static fn (self $app, Panel $panel): self => $app->cloneWith(panel: $panel),
            ),
            Component::bind(
                ComponentId::named('status-footer'),
                $this->footer,
                static fn (self $app, Footer $footer): self => $app->cloneWith(footer: $footer),
            ),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final class AppInteractivesTest extends TestCase
{
    #[Test]
    public function aPressReachesABoundInteractiveThatTakesNoFocus(): void
    {
        $app = new PressCountingApp(widget: new PressCountingWidget());

        $result = $app->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(PressCountingApp::class, $result->model);
        self::assertSame(1, $result->model->widget->presses, 'bind() accepts it, so routing must reach it');
    }

    #[Test]
    public function aPressReachesAnUnfocusableWidgetSharingTheScreenWithAFocusableOne(): void
    {
        $app = new PressCountingBesidePanelApp(
            panel: Panel::new('Test')->withFocus(true),
            widget: new PressCountingWidget(),
        );

        $result = $app->update(
            new MouseMsg(2, 23, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(PressCountingBesidePanelApp::class, $result->model);
        self::assertSame(1, $result->model->widget->presses, 'a focusable sibling must not swallow the pointer');
    }

    #[Test]
    public function layerOneTickBroadcastsToInteractives(): void
    {
        $spinner = new TickTally();
        $footer = Footer::new()->withRight($spinner);
        $app = new FooterOnlyApp(footer: $footer);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertNotSame($app, $result->model);
    }

    #[Test]
    public function layerTwoTickBroadcastsToInteractives(): void
    {
        $spinner = new TickTally();
        $footer = Footer::new()->withRight($spinner);
        $panel = Panel::new('Test')->withFocus(true);
        $app = new PanelAndFooterApp(panel: $panel, footer: $footer);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertNotSame($app, $result->model);
    }

    #[Test]
    public function emptyInteractivesNoChange(): void
    {
        $footer = Footer::new()->withRight(Text::new('static'));
        $app = new FooterOnlyApp(footer: $footer);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertSame($app, $result->model);
    }

    #[Test]
    public function broadcastBuildsEachImmutableViewOnceWhenNoInteractives(): void
    {
        $viewCalls = new ViewCallProbe();
        $app = new NoInteractivesTickApp(viewCalls: $viewCalls);
        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertInstanceOf(NoInteractivesTickApp::class, $result->model);
        self::assertSame(1, $result->model->ticks);
        self::assertSame(
            2,
            $viewCalls->count,
            'The original and onTick-updated app values must each build one cached view tree',
        );
        self::assertSame(0, $viewCalls->bindingsCount, 'An empty broadcast must not construct a child dispatcher');
    }

    #[Test]
    public function anUnmountedInteractiveIsPassiveAndNotBroadcastTo(): void
    {
        $widget = new RectCapturingInteractive();
        $app = new DetachedCapturingApp(widget: $widget);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(120, 40));

        self::assertSame($app, $result->model);
        self::assertSame(0, $result->model->widget->capturedWidth);
        self::assertSame(0, $result->model->widget->capturedHeight);
    }

    #[Test]
    public function broadcastPropagatesCommandFromInteractive(): void
    {
        $widget = new CommandEmittingWidget();
        $app = new CommandInteractiveApp(widget: $widget);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertInstanceOf(ExecCmd::class, $result->command);
    }

    #[Test]
    public function broadcastBatchesAppCommandWithWidgetCommand(): void
    {
        $widget = new CommandEmittingWidget();
        $app = new CommandWithTickApp(widget: $widget);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
        self::assertInstanceOf(TickCmd::class, $result->command->commands[0]);
        self::assertInstanceOf(ExecCmd::class, $result->command->commands[1]);
    }

    #[Test]
    public function broadcastToWithNoCommandsReturnsNull(): void
    {
        $footer = Footer::new()->withRight(Text::new('static'));
        $app = new FooterOnlyApp(footer: $footer);

        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertNull($result->command);
    }

    #[Test]
    public function broadcastToCollectsCommandsFromMultipleInteractives(): void
    {
        $app = new DualCommandInteractiveApp(
            widgetA: new CommandEmittingWidget(),
            widgetB: new CommandEmittingWidget(),
        );
        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
    }
}

final readonly class NoInteractivesTickApp extends CopyableTestApp
{
    public function __construct(
        public int $ticks = 0,
        public ViewCallProbe $viewCalls = new ViewCallProbe(),
    ) {
    }

    public function view(): Widget
    {
        ++$this->viewCalls->count;

        return Text::new("Ticks: {$this->ticks}");
    }

    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::changed($this->cloneWith(ticks: $this->ticks + 1));
    }

    protected function globalKeybindings(): Keybindings
    {
        ++$this->viewCalls->bindingsCount;

        return Keybindings::empty();
    }
}

final class ViewCallProbe
{
    public int $count = 0;
    public int $bindingsCount = 0;
}

final readonly class PressCountingWidget implements Interactive
{
    public function __construct(public int $presses = 0)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick()) {
            return new UpdateResult(new self(presses: $this->presses + 1));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class PressCountingApp extends CopyableTestApp
{
    public function __construct(public PressCountingWidget $widget)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('presses'),
            $this->widget,
            static fn (self $app, PressCountingWidget $widget): self => $app->cloneWith(widget: $widget),
        );
    }
}

final readonly class PressCountingBesidePanelApp extends CopyableTestApp
{
    public function __construct(
        public Panel $panel,
        public PressCountingWidget $widget,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                ComponentId::named('panel'),
                $this->panel,
                static fn (self $app, Panel $panel): self => $app->cloneWith(panel: $panel),
            ),
            Component::bind(
                ComponentId::named('presses'),
                $this->widget,
                static fn (self $app, PressCountingWidget $widget): self => $app->cloneWith(widget: $widget),
            ),
        );
    }
}

final readonly class RectCapturingInteractive implements Interactive
{
    public function __construct(
        public int $capturedWidth = 0,
        public int $capturedHeight = 0,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof TickMsg) {
            return new UpdateResult(new self(capturedWidth: $area->width, capturedHeight: $area->height));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class DetachedCapturingApp extends CopyableTestApp
{
    public function __construct(
        public RectCapturingInteractive $widget,
    ) {
    }

    public function view(): Widget
    {
        return Text::new('no interactive in tree');
    }
}

final readonly class CommandEmittingWidget implements Interactive
{
    public function __construct(
        private int $frame = 0,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof TickMsg) {
            return new UpdateResult(
                new self(frame: $this->frame + 1),
                Cmd::exec(['echo', 'tick']),
            );
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class CommandInteractiveApp extends CopyableTestApp
{
    public function __construct(
        public CommandEmittingWidget $widget,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Text::new('content'),
            Component::bind(
                ComponentId::named('command-source'),
                $this->widget,
                static fn (self $app, CommandEmittingWidget $widget): self => $app->cloneWith(widget: $widget),
            ),
        );
    }
}

final readonly class CommandWithTickApp extends CopyableTestApp
{
    public function __construct(
        public CommandEmittingWidget $widget,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Text::new('content'),
            Component::bind(
                ComponentId::named('command-source'),
                $this->widget,
                static fn (self $app, CommandEmittingWidget $widget): self => $app->cloneWith(widget: $widget),
            ),
        );
    }

    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
    {
        return $this->withCmd(Cmd::tick(0.1));
    }
}

final readonly class DualCommandInteractiveApp extends CopyableTestApp
{
    public function __construct(
        public CommandEmittingWidget $widgetA,
        public CommandEmittingWidget $widgetB,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                ComponentId::named('command-source-a'),
                $this->widgetA,
                static fn (self $app, CommandEmittingWidget $widget): self => $app->cloneWith(widgetA: $widget),
            ),
            Component::bind(
                ComponentId::named('command-source-b'),
                $this->widgetB,
                static fn (self $app, CommandEmittingWidget $widget): self => $app->cloneWith(widgetB: $widget),
            ),
        );
    }
}
