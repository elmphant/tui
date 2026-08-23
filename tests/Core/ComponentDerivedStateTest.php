<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentDerivedStateTest extends TestCase
{
    /**
     * A model may keep what the component changed rather than the component, and
     * build the widget back from it — the same answer, a different instance.
     */
    #[Test]
    public function aComponentRebuiltFromWhatTheModelKeptIsAcceptedBack(): void
    {
        $scrolled = (new DerivedScrollApp())->update(
            new KeyMsg(Key::Down),
            new Rect(0, 0, 20, 5),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertInstanceOf(DerivedScrollApp::class, $scrolled->model);
        self::assertSame(1, $scrolled->model->scroll);
    }

    /**
     * One key can change both the component's own state and something the host
     * derives from it, such as a title. The model kept the change, so the rebuilt
     * widget must be accepted — refusing it loses the keystroke.
     */
    #[Test]
    public function aComponentRebuiltWithDecorationTheSameKeystrokeChangedIsAcceptedBack(): void
    {
        $scrolled = (new DerivedTitleApp())->update(
            new KeyMsg(Key::Down),
            new Rect(0, 0, 20, 5),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertInstanceOf(DerivedTitleApp::class, $scrolled->model);
        self::assertSame(1, $scrolled->model->scroll);
    }
}

final readonly class DerivedTitleApp extends App
{
    public function __construct(public int $scroll = 0)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'pane',
            Panel::new('At row ' . $this->scroll)
                ->withChildren([
                    Text::new('one'),
                    Text::new('two'),
                    Text::new('three'),
                    Text::new('four'),
                    Text::new('five'),
                    Text::new('six'),
                ])
                ->withScrollOffset($this->scroll),
            static fn (self $app, Panel $pane): self => $app->cloneWith(scroll: $pane->scrollOffset()),
        );
    }
}

final readonly class DerivedScrollApp extends App
{
    public function __construct(public int $scroll = 0)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'pane',
            Panel::new('Pane')
                ->withChildren([
                    Text::new('one'),
                    Text::new('two'),
                    Text::new('three'),
                    Text::new('four'),
                    Text::new('five'),
                    Text::new('six'),
                ])
                ->withScrollOffset($this->scroll),
            static fn (self $app, Panel $pane): self => $app->cloneWith(scroll: $pane->scrollOffset()),
        );
    }
}
