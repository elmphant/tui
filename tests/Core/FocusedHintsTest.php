<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\TabBar;
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

        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState(ComponentId::named('second')));

        (new HintingApp($read))->render(new Frame(20, 3), $context);

        self::assertSame([['space' => 'pick']], $read->getArrayCopy());
    }

    #[Test]
    public function theKeysNamedIncludeTheOnesTheAppsGlobalBindingsAdded(): void
    {
        $driven = ModelHarness::for(new VimTabsApp(), 40, 10)->initialize();

        self::assertSame(
            ['h' => 'previous tab', 'l' => 'next tab'],
            $driven->focusedHints(),
            'a footer names the keys vim mode made active, not the ones it was authored with',
        );
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

        return Layout::vertical(
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
        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
