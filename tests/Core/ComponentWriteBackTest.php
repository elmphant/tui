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
 * Two controls may reach the same field — a list and the button that jumps it
 * back to the end. A press on one of them must not have the other's untouched
 * widget written back over what it just did.
 */
final class ComponentWriteBackTest extends TestCase
{
    #[Test]
    public function aPressOnOneComponentSurvivesTheOtherKeepingItsPlace(): void
    {
        $result = (new SharedTallyApp())->update(
            new MouseMsg(0, 1, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(20, 2),
            new FocusState(ComponentId::named('keeper')),
        );

        self::assertInstanceOf(SharedTallyApp::class, $result->model);
        self::assertSame(
            99,
            $result->model->tally,
            'the keeper was never pressed, so nothing of its should land on top of the reset',
        );
    }
}

final readonly class SharedTallyApp extends App
{
    use Cloneable;

    public function __construct(public int $tally = 0)
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'keeper',
                TallyKeeper::of($this->tally)->withFocus(true),
                static fn (self $app, TallyKeeper $keeper): self => $app->cloneWith(tally: $keeper->tally()),
            ),
            Component::bind(
                'resetter',
                TallyResetter::of($this->tally),
                static fn (self $app, TallyResetter $resetter): self => $app->cloneWith(tally: $resetter->tally()),
            ),
        );
    }
}

/**
 * Holds the tally and answers nothing: whatever it is handed, it keeps its place.
 */
final readonly class TallyKeeper implements Focusable
{
    use Cloneable;

    private function __construct(private int $tally, private bool $focused = false)
    {
    }

    public static function of(int $tally): self
    {
        return new self($tally);
    }

    public function tally(): int
    {
        return $this->tally;
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
        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class TallyResetter implements Focusable
{
    use Cloneable;

    private function __construct(private int $tally, private bool $focused = false)
    {
    }

    public static function of(int $tally): self
    {
        return new self($tally);
    }

    public function tally(): int
    {
        return $this->tally;
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
        if ($message instanceof MouseMsg && $message->isClick() && $message->inRect($area)) {
            return UpdateResult::changed($this->cloneWith(tally: 99));
        }

        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
