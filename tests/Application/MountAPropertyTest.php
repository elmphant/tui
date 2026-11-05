<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
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

    #[Test]
    public function aModelMountedAtAKeyKeepsTheIdItIsGiven(): void
    {
        $harness = ModelHarness::for(new FieldsApp(['a' => new MountedField(TextInput::new())]), 30, 3)->init();

        $harness->type('hi');

        self::assertSame('pane-a/field', $harness->focused()?->value);
        $model = $harness->model();
        self::assertInstanceOf(FieldsApp::class, $model);
        self::assertSame('hi', $model->fields['a']->field->value());
    }
}

final readonly class MountedField implements Reducer
{
    public function __construct(public TextInput $field)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Component::bind('field', $this->field, static fn (self $model, TextInput $field): self => new self($field));
    }
}

final readonly class FieldsApp extends App
{
    /**
     * @param array<string, MountedField> $fields
     */
    public function __construct(public array $fields)
    {
    }

    public function view(): Widget
    {
        return Layout::column(...array_map(fn (string $key): Widget => $this->mount('fields', $this->fields[$key]->view(), id: "pane-{$key}", at: $key), array_keys($this->fields)));
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
