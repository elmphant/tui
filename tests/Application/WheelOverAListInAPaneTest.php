<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WheelOverAListInAPaneTest extends TestCase
{
    #[Test]
    public function aWheelOverTheFocusedListScrollsThePaneItSitsIn(): void
    {
        $harness = ModelHarness::for(new ListInAPaneApp(ScrollPane::of(
            ListView::fromStrings(array_map(static fn (int $n): string => 'item' . $n, range(1, 30))),
        )), 20, 6)->initialize();
        self::assertSame('pane/0', $harness->focused()?->value);

        $harness->wheel('item3', 3);

        self::assertStringNotContainsString('item1', $harness->renderScreen()->plainText());
    }
}

final readonly class ListInAPaneApp extends App
{
    public function __construct(public ScrollPane $pane)
    {
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }
}
