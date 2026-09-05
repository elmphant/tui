<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraftedChildRoutingTest extends TestCase
{
    #[Test]
    public function aKeyTheFieldLeavesAloneScrollsThePaneAroundIt(): void
    {
        $paged = ScrollableFormApp::seeded()->update(
            new KeyMsg(Key::PageDown),
            Rect::fromSize(30, 6),
            new FocusState(ComponentId::named('form/0')),
        )->model;

        self::assertInstanceOf(ScrollableFormApp::class, $paged);
        self::assertGreaterThan(0, $paged->form->scrollOffset());
    }

    /**
     * Whichever of the two is written back first.
     */
    #[Test]
    public function aWheelThatMovesBothThePaneAndAChildKeepsBothChanges(): void
    {
        $wheeled = WheelCounterApp::seeded()->update(
            new MouseMsg(3, 1, MouseAction::WheelDown, MouseButton::None),
            Rect::fromSize(20, 6),
        )->model;

        self::assertInstanceOf(WheelCounterApp::class, $wheeled);
        $counter = [...$wheeled->outer->children()][0];
        self::assertInstanceOf(WheelCounter::class, $counter);
        self::assertSame(1, $counter->count);
        self::assertSame(1, $wheeled->outer->scrollOffset());
    }

    #[Test]
    public function typingIntoTheFieldTheReaderWheeledAwayFromBringsItBack(): void
    {
        $harness = ModelHarness::for(ScrollableFormApp::seeded(), 30, 6);

        foreach (range(1, 3) as $ignored) {
            $harness->dispatch(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None));
        }
        self::assertStringNotContainsString('f0', $harness->renderScreen()->plainText(), 'precondition: the field is off screen');

        $harness->dispatch(new KeyMsg('x'));

        self::assertStringContainsString('f0x', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function aTickThatChangesAChildLeavesTheReadersScrollAlone(): void
    {
        $harness = ModelHarness::for(ScrolledLogApp::scrolledDown(), 30, 6);

        $harness->dispatch(new TickMsg(0.0));

        self::assertStringContainsString('row10', $harness->renderScreen()->plainText());
    }
}

final readonly class ScrollableFormApp extends App
{
    public function __construct(public Panel $form = new Panel())
    {
    }

    public static function seeded(): self
    {
        $panel = Panel::new('Form');

        foreach (range(0, 9) as $index) {
            $panel = $panel->add(TextInput::new()->withValue('f' . $index));
        }

        return new self($panel);
    }

    public function view(): Widget
    {
        return Component::bind('form', $this->form, static fn (self $app, Panel $form): self => $app->cloneWith(form: $form));
    }
}

final readonly class WheelCounter implements Focusable
{
    public function __construct(public int $count = 0, private bool $focused = false)
    {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->count, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->action === MouseAction::WheelDown && $message->inRect($area)) {
            return new UpdateResult(new self($this->count + 1, $this->focused));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, 'wheel ' . $this->count);
    }
}

final readonly class TickCounter implements Interactive
{
    public function __construct(public int $ticks = 0)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof TickMsg
            ? new UpdateResult(new self($this->ticks + 1))
            : new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, 'ticks ' . $this->ticks);
    }
}

final readonly class ScrolledLogApp extends App
{
    public function __construct(public Panel $log = new Panel())
    {
    }

    public static function scrolledDown(): self
    {
        $panel = Panel::new('Log')->add(new TickCounter());

        foreach (range(1, 15) as $row) {
            $panel = $panel->add(Text::new('row' . $row));
        }

        return new self($panel->withScrollOffset(10));
    }

    public function view(): Widget
    {
        return Component::bind('log', $this->log, static fn (self $app, Panel $log): self => $app->cloneWith(log: $log));
    }
}

final readonly class WheelCounterApp extends App
{
    public function __construct(public Panel $outer = new Panel())
    {
    }

    public static function seeded(): self
    {
        $panel = Panel::new('Outer')->add(new WheelCounter());

        foreach (range(1, 12) as $row) {
            $panel = $panel->add(Text::new('row' . $row));
        }

        return new self($panel);
    }

    public function view(): Widget
    {
        return Component::bind('outer', $this->outer, static fn (self $app, Panel $outer): self => $app->cloneWith(outer: $outer));
    }
}
