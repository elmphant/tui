<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessCarriesTheFocusedHintsTest extends TestCase
{
    #[Test]
    public function theHintsFollowTheKeyboard(): void
    {
        $driven = ModelHarness::for(new TwoHintingPanesApp(), 20, 2)->initialize();

        self::assertSame(['a' => 'first thing'], $driven->focusedHints());

        $driven->dispatch(new KeyMsg('n'));

        self::assertSame(
            ['b' => 'second thing'],
            $driven->focusedHints(),
            'the keys named are the keys of whatever the keyboard moved to',
        );
    }
}

final readonly class TwoHintingPanesApp extends App
{
    public function __construct(
        public HintingPane $first = new HintingPane('a', 'first thing'),
        public HintingPane $second = new HintingPane('b', 'second thing'),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, HintingPane $pane): self => $app->cloneWith(first: $pane)),
            Component::bind('second', $this->second, static fn (self $app, HintingPane $pane): self => $app->cloneWith(second: $pane)),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->key === 'n' ? $this->then(Cmd::focusNext()) : UpdateResult::ignored($this);
    }
}

final readonly class HintingPane implements Focusable, PublishesHints
{
    public function __construct(
        private string $key,
        private string $what,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->key, $this->what, $focused);
    }

    /**
     * @return array<string, string>
     */
    public function hints(): array
    {
        return [$this->key => $this->what];
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
        $frame->putString($area->x, $area->y, $this->key);
    }
}
