<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DropdownInScrollPaneKeysTest extends TestCase
{
    #[Test]
    public function downOnTheLastOptionOfAnOpenDropdownLeavesThePaneWhereItWas(): void
    {
        $harness = self::opened();
        $harness->dispatch(new KeyMsg(Key::Down));
        $harness->dispatch(new KeyMsg(Key::Down));
        $offset = self::appOf($harness)->pane->scrollOffset();

        $harness->dispatch(new KeyMsg(Key::Down));

        self::assertSame($offset, self::appOf($harness)->pane->scrollOffset());
    }

    /**
     * @return ModelHarness<ScrolledDropdownApp>
     */
    private static function opened(): ModelHarness
    {
        $harness = ModelHarness::for(ScrolledDropdownApp::new(), 40, 12);
        $harness->renderFrame();
        $harness->dispatch(new MouseMsg(30, 5, MouseAction::WheelDown, MouseButton::None, 6));
        $harness->renderFrame();
        self::assertSame('pane/10', $harness->focused()?->value);

        $harness->dispatch(new KeyMsg(' '));
        $harness->renderFrame();

        return $harness;
    }

    /**
     * @param ModelHarness<ScrolledDropdownApp> $harness
     */
    private static function appOf(ModelHarness $harness): ScrolledDropdownApp
    {
        $model = $harness->model();
        if (!$model instanceof ScrolledDropdownApp) {
            throw new RuntimeException('lost the app');
        }

        return $model;
    }
}

final readonly class ScrolledDropdownApp extends App
{
    use Cloneable;

    public function __construct(public ScrollPane $pane)
    {
    }

    public static function new(): self
    {
        $rows = array_map(static fn (int $row): Widget => Text::new('row' . $row), range(0, 29));
        $rows[10] = Dropdown::new(Select::new(['alpha', 'beta', 'gamma']));

        return new self(ScrollPane::of(...$rows));
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }
}
