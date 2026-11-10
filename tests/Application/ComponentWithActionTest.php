<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\KeyHint;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentWithActionTest extends TestCase
{
    #[Test]
    public function aDeclaredKeyRunsItsHandlerOnTheStoredApp(): void
    {
        $harness = ModelHarness::for(new ListWithActionsApp(ListView::fromStrings(['alpha', 'beta'])), 40, 6);

        $harness->press('down', 'd');

        $model = $harness->model();
        self::assertInstanceOf(ListWithActionsApp::class, $model);
        self::assertSame(['alpha'], array_map(static fn ($item): string => $item->title(), $model->list->items()));
    }

    #[Test]
    public function theFooterNamesTheActionByItsLabel(): void
    {
        $screen = ModelHarness::for(new ListWithActionsApp(ListView::fromStrings(['alpha', 'beta'])), 60, 6)->screen();

        self::assertStringContainsString('d remove', $screen->lineAt(5));
    }

    #[Test]
    public function aMountedBindKeepsItsLabelInTheFooter(): void
    {
        $screen = ModelHarness::for(new MountingListApp(new MountedListApp(ListView::fromStrings(['alpha', 'beta']))), 60, 6)->screen();

        self::assertStringContainsString('d remove', $screen->lineAt(5));
    }

    #[Test]
    public function aMountedBindRunsItsHandlerOnce(): void
    {
        $harness = ModelHarness::for(new MountingListApp(new MountedListApp(ListView::fromStrings(['alpha', 'beta', 'gamma']))), 60, 6);

        $harness->press('d');

        $model = $harness->model();
        self::assertInstanceOf(MountingListApp::class, $model);
        self::assertSame(['beta', 'gamma'], array_map(static fn ($item): string => $item->title(), $model->inner->list->items()));
    }

    #[Test]
    public function theKeyHelpListsTheActionHereByTheSameLabel(): void
    {
        $harness = ModelHarness::for(new ListWithActionsApp(ListView::fromStrings(['alpha', 'beta'])), 60, 6);
        $focused = $harness->focused();
        self::assertNotNull($focused);

        $help = $harness->model()->keyHelp(FocusState::on($focused));

        self::assertEquals([new KeyHint('d', 'remove', KeyPattern::fromString('d'))], array_values(array_filter(
            $help['Here'],
            static fn (KeyHint $entry): bool => $entry->label === 'd',
        )));
    }
}

final readonly class ListWithActionsApp extends App
{
    public function __construct(public ListView $list)
    {
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('list')
                ->withAction('delete', ['d'], static fn (self $app): self => new self($app->list->withoutCursorItem()), 'remove'),
            Footer::new()->withLeft(FocusHints::new()),
        );
    }
}

final readonly class MountedListApp extends App
{
    public function __construct(public ListView $list)
    {
    }

    public function view(): Widget
    {
        return $this->bind('list')
            ->withAction('delete', ['d'], static fn (self $app): self => new self($app->list->withoutCursorItem()), 'remove');
    }
}

final readonly class MountingListApp extends App
{
    public function __construct(public MountedListApp $inner)
    {
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->mount('inner', $this->inner->view()),
            Footer::new()->withLeft(FocusHints::new()),
        );
    }
}
