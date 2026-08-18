<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Component;
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

/**
 * A widget may want to act on a press only once the keyboard is already on it —
 * the first press brings it, the next one acts. Deciding that means being told,
 * for a pointer message as for a key, which the runtime already knows.
 */
final class APointerTellsAComponentItHoldsTheKeyboardTest extends TestCase
{
    #[Test]
    public function theComponentTheKeyboardIsOnIsToldSoWhenAPressArrives(): void
    {
        $result = (new WatchingApp())->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(20, 2),
            new FocusState(ComponentId::named('watcher')),
        );

        self::assertInstanceOf(WatchingApp::class, $result->model);
        self::assertTrue($result->model->watcher->sawTheKeyboard());
    }
}

final readonly class WatchingApp extends App
{
    use Cloneable;

    public function __construct(
        public KeyboardWatcher $watcher = new KeyboardWatcher(),
        public KeyboardWatcher $bystander = new KeyboardWatcher(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'watcher',
                $this->watcher,
                static fn (self $app, KeyboardWatcher $w): self => $app->cloneWith(watcher: $w),
            ),
            Component::bind(
                'bystander',
                $this->bystander,
                static fn (self $app, KeyboardWatcher $w): self => $app->cloneWith(bystander: $w),
            ),
        );
    }
}

/**
 * Records what it was told about the keyboard the last time it was pressed.
 */
final readonly class KeyboardWatcher implements Focusable
{
    use Cloneable;

    public function __construct(
        private bool $focused = false,
        private ?bool $sawTheKeyboard = null,
    ) {
    }

    public function sawTheKeyboard(): ?bool
    {
        return $this->sawTheKeyboard;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick()) {
            return UpdateResult::changed($this->cloneWith(sawTheKeyboard: $this->focused));
        }

        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
