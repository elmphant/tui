<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A component that seats a cursor of its own cannot derive the end it was entered
 * from, so the arrival has to carry it.
 */
final class ArrivalSaysWhichWayTheRingWalkedTest extends TestCase
{
    #[Test]
    public function walkingForwardArrivesSayingSo(): void
    {
        self::assertSame(FocusMove::Next, $this->walkedInto(new KeyMsg(Key::Tab))->second->arrivedBy);
    }

    #[Test]
    public function walkingBackwardArrivesSayingSo(): void
    {
        self::assertSame(
            FocusMove::Previous,
            $this->walkedInto(new KeyMsg(Key::Tab, shift: true))->second->arrivedBy,
            'a shift+tab in is the far end of the component, not the near one',
        );
    }

    private function walkedInto(KeyMsg $key): TwoStopApp
    {
        $driven = ModelHarness::for(new TwoStopApp(), 20, 2)->initialize();
        $driven->dispatch($key);
        $model = $driven->model();

        self::assertInstanceOf(TwoStopApp::class, $model);

        return $model;
    }
}

final readonly class TwoStopApp extends App
{
    public function __construct(
        public ArrivalRecorder $first = new ArrivalRecorder(),
        public ArrivalRecorder $second = new ArrivalRecorder(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, ArrivalRecorder $w): self => $app->cloneWith(first: $w)),
            Component::bind('second', $this->second, static fn (self $app, ArrivalRecorder $w): self => $app->cloneWith(second: $w)),
        );
    }
}

final readonly class ArrivalRecorder implements Focusable
{
    public function __construct(public ?FocusMove $arrivedBy = null)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof FocusMsg
            ? UpdateResult::changed(new self($message->move))
            : UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
