<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountAPropertyTest extends TestCase
{
    #[Test]
    public function aMountedModelIsStoredBackInItsProperty(): void
    {
        $harness = ModelHarness::for(new OneCounterApp(new KeyCounter()), 30, 3)->init();

        $harness->press('x', 'x');

        $model = $harness->model();
        self::assertInstanceOf(OneCounterApp::class, $model);
        self::assertSame(2, $model->counter->count);
    }

    #[Test]
    public function aModelMountedAtAKeyIsStoredUnderThatKey(): void
    {
        $harness = ModelHarness::for(new CountersApp(['a' => new KeyCounter(), 'b' => new KeyCounter(5)]), 30, 3)->init();

        $harness->press('x');

        $model = $harness->model();
        self::assertInstanceOf(CountersApp::class, $model);
        self::assertSame(['a' => 1, 'b' => 5], array_map(static fn (KeyCounter $counter): int => $counter->count, $model->counters));
    }
}

final readonly class KeyCounter implements Reducer
{
    public function __construct(public int $count = 0)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($message instanceof KeyMsg && $message->key === 'x' ? new self($this->count + 1) : $this);
    }

    public function view(): Widget
    {
        return Text::new("count {$this->count}");
    }
}

final readonly class OneCounterApp extends App
{
    public function __construct(public KeyCounter $counter)
    {
    }

    public function view(): Widget
    {
        return $this->mount('counter', $this->counter->view());
    }
}

final readonly class CountersApp extends App
{
    /**
     * @param array<string, KeyCounter> $counters
     */
    public function __construct(public array $counters)
    {
    }

    public function view(): Widget
    {
        return Layout::column(...array_map(fn (string $key): Widget => $this->mount('counters', $this->counters[$key]->view(), at: $key), array_keys($this->counters)));
    }
}
