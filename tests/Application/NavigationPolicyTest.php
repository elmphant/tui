<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NavigationPolicyTest extends TestCase
{
    #[Test]
    public function aPointerOnlyComponentIsClickableButTabWalksPastIt(): void
    {
        $app = new NavigationPolicyApp();

        self::assertEquals([ComponentId::named('details')], $app->focusableIds(), 'tab has one stop');

        $clicked = $app->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 2),
        );

        self::assertEquals(
            Cmd::focus(ComponentId::named('output')),
            $clicked->command,
            'and a press still asks for the keyboard of the pane it landed in',
        );
    }

    /**
     * Pointer-only panes must survive focus resolution even though Tab skips them.
     */
    #[Test]
    public function aClickOnAPointerOnlyPaneLeavesTheKeyboardThere(): void
    {
        $harness = ModelHarness::for(new NavigationPolicyApp(), 80, 2)
            ->dispatch(new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left));

        self::assertEquals(ComponentId::named('output'), $harness->focused());
    }

    #[Test]
    public function aPaneWithNothingInItIsNotOfferedTheKeyboard(): void
    {
        $app = new EmptyPaneApp(new EmptyPane(ready: false), new EmptyPane(ready: true));

        self::assertEquals([ComponentId::named('details')], $app->focusableIds());
        self::assertEquals(
            [ComponentId::named('output'), ComponentId::named('details')],
            (new EmptyPaneApp(new EmptyPane(ready: true), new EmptyPane(ready: true)))->focusableIds(),
            'and the same pane with something in it is back on the ring',
        );
    }
}

final readonly class NavigationPolicyApp extends App
{
    public function __construct(
        public NavigationPane $output = new NavigationPane(Navigation::Pointer),
        public NavigationPane $details = new NavigationPane(Navigation::Regular),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('output', $this->output, static fn (self $app, NavigationPane $pane): self => $app->cloneWith(output: $pane)),
            Component::bind('details', $this->details, static fn (self $app, NavigationPane $pane): self => $app->cloneWith(details: $pane)),
        );
    }
}

final readonly class EmptyPaneApp extends App
{
    public function __construct(
        public EmptyPane $output = new EmptyPane(),
        public EmptyPane $details = new EmptyPane(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('output', $this->output, static fn (self $app, EmptyPane $pane): self => $app->cloneWith(output: $pane)),
            Component::bind('details', $this->details, static fn (self $app, EmptyPane $pane): self => $app->cloneWith(details: $pane)),
        );
    }
}

final readonly class EmptyPane implements Navigates
{
    public function __construct(
        private bool $ready = true,
        private bool $focused = false,
    ) {
    }

    public function navigation(): Navigation
    {
        return $this->ready ? Navigation::Regular : Navigation::Never;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->ready, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class NavigationPane implements Navigates
{
    public function __construct(
        private Navigation $navigation = Navigation::Regular,
        private bool $focused = false,
    ) {
    }

    public function navigation(): Navigation
    {
        return $this->navigation;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->navigation, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
