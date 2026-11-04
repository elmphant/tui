<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppActionsTest extends TestCase
{
    #[Test]
    public function aDeclaredKeyRunsItsHandlerWhereverTheKeyboardIs(): void
    {
        $harness = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha'])), 60, 6);

        $harness->press('a');

        $model = $harness->model();
        self::assertInstanceOf(ArchivingApp::class, $model);
        self::assertSame(1, $model->archived);
    }

    #[Test]
    public function theFooterLeadsWithTheAppsActionsByTheirLabels(): void
    {
        $screen = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha'])), 80, 6)->screen();

        self::assertStringStartsWith(' Ctrl+Q  quit   a  archive all   ↑/↓  move', $screen->lineAt(5));
    }

    #[Test]
    public function theFooterLeavesOutAnActionKeyAFocusedFieldTakesAsText(): void
    {
        $screen = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha']), field: TextInput::new()), 80, 6)->screen();

        self::assertStringNotContainsString('archive all', $screen->lineAt(5));
        self::assertStringContainsString('Ctrl+Q  quit', $screen->lineAt(5));
    }
}

final readonly class ArchivingApp extends App
{
    public function __construct(public ListView $list, public int $archived = 0, public ?TextInput $field = null)
    {
    }

    public function view(): Widget
    {
        return Layout::column(...[
            ...$this->field === null ? [] : [$this->bind('field')],
            $this->bind('list'),
            Footer::new()->withLeft(FocusHints::new()),
        ]);
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('archive', ['a'], static fn (self $app): self => new self($app->list, $app->archived + 1, $app->field), 'archive all');
    }
}
