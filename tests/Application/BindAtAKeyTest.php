<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BindAtAKeyTest extends TestCase
{
    #[Test]
    public function aWidgetBoundAtAKeyIsStoredUnderThatKey(): void
    {
        $harness = ModelHarness::for(ColumnsApp::start(), 40, 6)->init();

        $harness->press('tab', 'down');

        $model = $harness->model();
        self::assertInstanceOf(ColumnsApp::class, $model);
        self::assertSame(['todo' => 0, 'done' => 1], array_map(static fn (ListView $list): int => $list->cursor(), $model->columns));
    }

    #[Test]
    public function itsIdNamesTheKey(): void
    {
        $harness = ModelHarness::for(ColumnsApp::start(), 40, 6)->init();

        self::assertSame('columns.todo', $harness->focused()?->value);
    }

    #[Test]
    public function reorderingTheCollectionKeepsEachWidgetAndTheKeyboardWithItsKey(): void
    {
        $harness = ModelHarness::for(ColumnsApp::start(), 40, 6)->init();
        $harness->press('tab', 'down');

        $harness->press('ctrl+r');

        $model = $harness->model();
        self::assertInstanceOf(ColumnsApp::class, $model);
        self::assertSame(['done' => 1, 'todo' => 0], array_map(static fn (ListView $list): int => $list->cursor(), $model->columns));
        self::assertSame('columns.done', $harness->focused()?->value);
    }

    #[Test]
    public function theHandlerOfAKeyedWidgetSeesItStoredUnderItsKey(): void
    {
        $harness = ModelHarness::for(ColumnsApp::start(), 40, 6)->init();

        $harness->press('tab', 'down', 'enter');

        $model = $harness->model();
        self::assertInstanceOf(ColumnsApp::class, $model);
        self::assertSame('done: d at 1', $model->heard);
    }
}

final readonly class ColumnsApp extends App
{
    /**
     * @param array<string, ListView> $columns
     */
    public function __construct(public array $columns, public string $heard = '')
    {
    }

    public static function start(): self
    {
        return new self(['todo' => ListView::fromStrings(['a', 'b']), 'done' => ListView::fromStrings(['c', 'd'])]);
    }

    public function view(): Widget
    {
        return Layout::row(...array_map(fn (string $key): Widget => $this->bind('columns', at: $key)->on(
            Chosen::class,
            static fn (self $app, Chosen $chosen): self => new self($app->columns, sprintf('%s: %s at %d', $key, $app->columns[$key]->cursorItem()?->title(), $app->columns[$key]->cursor())),
        ), array_keys($this->columns)));
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('reverse', ['ctrl+r'], static fn (self $app): self => new self(array_reverse($app->columns, true)));
    }
}
