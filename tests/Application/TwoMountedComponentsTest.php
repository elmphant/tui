<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TwoMountedComponentsTest extends TestCase
{
    #[Test]
    public function oneTickCountsOnBothComponentsOfAMountedModel(): void
    {
        $harness = ModelHarness::for(new MountedTickerHost(new MountedTickerPair()), 30, 4);

        $harness->dispatch(new TickMsg(1.0));

        $pair = $harness->model()->pair;
        self::assertSame([1, 1], [$pair->first->ticks, $pair->second->ticks]);
    }
}

final readonly class MountedTicker implements Focusable
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
        return new UpdateResult($message instanceof TickMsg ? new self($this->ticks + 1) : $this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, (string)$this->ticks);
    }
}

final readonly class MountedTickerPair implements Reducer
{
    public function __construct(
        public MountedTicker $first = new MountedTicker(),
        public MountedTicker $second = new MountedTicker(),
    ) {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $pair, MountedTicker $first): self => new self($first, $pair->second)),
            Component::bind('second', $this->second, static fn (self $pair, MountedTicker $second): self => new self($pair->first, $second)),
        );
    }
}

final readonly class MountedTickerHost extends App
{
    public function __construct(public MountedTickerPair $pair)
    {
    }

    public function view(): Widget
    {
        return Mount::of($this->pair, $this->pair->view(), static fn (self $host, MountedTickerPair $pair): self => new self($pair));
    }
}
