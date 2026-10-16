<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PressArrivesKnowingTheKeyboardTest extends TestCase
{
    #[Test]
    public function aPressOnTheComponentHoldingTheKeyboardSaysSo(): void
    {
        $pressed = $this->press(row: 0);

        self::assertTrue($pressed->first->sawTheKeyboard);
    }

    #[Test]
    public function aPressThatBringsTheKeyboardSaysItWasElsewhere(): void
    {
        $pressed = $this->press(row: 1);

        self::assertFalse($pressed->second->sawTheKeyboard, 'this press is the one that moves it, so it is not acted on');
    }

    private function press(int $row): TwoPressTargetsApp
    {
        $updated = (new TwoPressTargetsApp())->update(
            new MouseMsg(0, $row, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 20, 2),
            new FocusState(ComponentId::named('first')),
        )->model;

        self::assertInstanceOf(TwoPressTargetsApp::class, $updated);

        return $updated;
    }
}

final readonly class TwoPressTargetsApp extends App
{
    public function __construct(
        public PressTarget $first = new PressTarget(),
        public PressTarget $second = new PressTarget(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, PressTarget $target): self => $app->cloneWith(first: $target)),
            Component::bind('second', $this->second, static fn (self $app, PressTarget $target): self => $app->cloneWith(second: $target)),
        );
    }
}

final readonly class PressTarget implements Focusable
{
    public function __construct(public bool $sawTheKeyboard = false, private bool $focused = false)
    {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->sawTheKeyboard, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof MouseMsg || !$message->inRect($area)) {
            return new UpdateResult($this);
        }

        return new UpdateResult(new self($message->keyboardWasHere, $this->focused));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
