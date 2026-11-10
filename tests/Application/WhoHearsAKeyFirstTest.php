<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WhoHearsAKeyFirstTest extends TestCase
{
    #[Test]
    public function anActionOnTheFocusedComponentIsHeardBeforeAnActionOfTheApp(): void
    {
        $harness = ModelHarness::for(KeyListenersApp::new())->init()->press('ctrl+x');

        self::assertSame(['component action'], $harness->model()->heard);
    }

    #[Test]
    public function anActionOfTheAppIsHeardBeforeOnKey(): void
    {
        $harness = ModelHarness::for(KeyListenersApp::new())->init()->press('ctrl+y');

        self::assertSame(['app action'], $harness->model()->heard);
    }

    #[Test]
    public function tabMovesTheKeyboardBeforeOnKeyHearsIt(): void
    {
        $harness = ModelHarness::for(KeyListenersApp::new())->init()->press('tab');

        self::assertSame('second', $harness->focused()?->value);
        self::assertSame([], $harness->model()->heard);
    }
}

final readonly class KeyListenersApp extends App
{
    /**
     * @param list<string> $heard
     */
    public function __construct(public ListView $first, public ListView $second, public array $heard = [])
    {
    }

    public static function new(): self
    {
        return new self(ListView::new([new SimpleListItem('one')]), ListView::new([new SimpleListItem('two')]));
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('first')->withAction('cut', ['ctrl+x'], static fn (self $app): self => $app->hearing('component action')),
            $this->bind('second'),
        );
    }

    protected function actions(): AppActions
    {
        return parent::actions()
            ->with('cut', ['ctrl+x'], static fn (self $app): self => $app->hearing('app action'))
            ->with('yank', ['ctrl+y'], static fn (self $app): self => $app->hearing('app action'));
    }

    protected function onKey(KeyMsg $message, InputContext $input): self
    {
        return $this->hearing('onKey');
    }

    private function hearing(string $listener): self
    {
        return $this->cloneWith(heard: [...$this->heard, $listener]);
    }
}
