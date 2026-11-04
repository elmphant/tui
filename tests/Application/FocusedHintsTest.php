<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use ArrayObject;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusedHintsTest extends TestCase
{
    #[Test]
    public function theKeysTheFocusedWidgetAnswersAreReadableBeforeItHasDrawn(): void
    {
        /** @var ArrayObject<int, array<string, string>> $read */
        $read = new ArrayObject();

        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(FocusState::on(ComponentId::named('second')));

        (new HintingApp($read))->render(new Frame(20, 3), $pass);

        self::assertSame([['space' => 'pick']], $read->getArrayCopy());
    }

    /**
     * A stale focus ID can outlive its component before runtime repair; resolving hints must still be safe.
     */
    #[Test]
    public function anIdNamingNoComponentNamesNoKeysRatherThanFailing(): void
    {
        /** @var ArrayObject<int, array<string, string>> $read */
        $read = new ArrayObject();

        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(FocusState::on(ComponentId::named('long-gone')));

        (new HintingApp($read))->render(new Frame(20, 3), $pass);

        self::assertSame([[]], $read->getArrayCopy());
    }

    #[Test]
    public function theKeysNamedIncludeTheOnesTheAppsGlobalBindingsAdded(): void
    {
        $driven = ModelHarness::for(new VimTabsApp(), 40, 10)->init();

        self::assertSame(
            ['H' => 'previous tab', 'L' => 'next tab'],
            $driven->focusedHints(),
            'a footer names the keys vim mode made active, not the ones it was authored with',
        );
    }

    #[Test]
    public function aWidgetNamesTheKeyThatMovesTheKeyboardOnByItsAction(): void
    {
        $driven = ModelHarness::for(new FocusCyclingApp(), 40, 10)->init();

        self::assertSame(['Tab' => 'next pane'], $driven->focusedHints());
    }

    #[Test]
    public function theKeyNamedFollowsTheAppsOwnFocusCycleBinding(): void
    {
        $driven = ModelHarness::for(new FocusCyclingApp(rebound: true), 40, 10)->init();

        self::assertSame(['Ctrl+N' => 'next pane'], $driven->focusedHints());
    }
}

final readonly class FocusCyclingApp extends App
{
    public function __construct(private bool $rebound = false)
    {
    }

    public function view(): Widget
    {
        return Component::bind('pane', new NextPaneHint(), static fn (self $app): self => $app);
    }

    protected function focusCycleKeybindings(): Keybindings
    {
        return $this->rebound
            ? new Keybindings([Actions::FOCUS_CYCLE => ['ctrl+n'], Actions::FOCUS_PREVIOUS_CYCLE => ['ctrl+p']])
            : parent::focusCycleKeybindings();
    }
}

final readonly class NextPaneHint implements Focusable, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public function __construct(
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(['next pane' => Actions::FOCUS_CYCLE]);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class VimTabsApp extends App
{
    public function __construct(private ?TabBar $bar = null)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'bar',
            $this->bar ?? TabBar::new()->addTab('A')->addTab('B'),
            static fn (self $app, TabBar $bar): self => $app->cloneWith(bar: $bar),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}

final readonly class HintingApp extends App
{
    /**
     * @param ArrayObject<int, array<string, string>> $read
     */
    public function __construct(private ArrayObject $read)
    {
    }

    public function view(): Widget
    {
        $read = $this->read;

        return Layout::column(
            new HintReader($read),
            Component::bind('first', new HintingField(['enter' => 'go']), static fn (self $app, Interactive $w): self => $app),
            Component::bind('second', new HintingField(['space' => 'pick']), static fn (self $app, Interactive $w): self => $app),
        );
    }
}

final readonly class HintReader implements Widget
{
    /**
     * @param ArrayObject<int, array<string, string>> $read
     */
    public function __construct(private ArrayObject $read)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->read[] = $context->focusedHints();
    }
}

final readonly class HintingField implements Interactive, PublishesHints
{
    /**
     * @param array<string, string> $hints
     */
    public function __construct(private array $hints)
    {
    }

    public function hints(): array
    {
        return $this->hints;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
