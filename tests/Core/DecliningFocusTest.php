<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\AcceptsFocus;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecliningFocusTest extends TestCase
{
    /**
     * A pane with nothing in it yet would otherwise be a stop on the way round.
     */
    #[Test]
    public function aComponentThatCannotTakeTheKeyboardIsNotOfferedIt(): void
    {
        $app = new DecliningFocusApp(new EmptyPane(ready: false), new EmptyPane(ready: true));

        self::assertEquals([ComponentId::named('details')], $app->focusableIds());
    }
}

final readonly class DecliningFocusApp extends App
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

final readonly class EmptyPane implements AcceptsFocus
{
    public function __construct(
        private bool $ready = true,
        private bool $focused = false,
    ) {
    }

    public function acceptsFocus(): bool
    {
        return $this->ready;
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
