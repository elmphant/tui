<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Core\FocusState;
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

        self::assertStringStartsWith(' Ctrl+Q quit   a archive all   ↑/↓ move', $screen->lineAt(5));
    }

    #[Test]
    public function anActionKeptOutOfTheFooterIsStillInTheKeyHelp(): void
    {
        $harness = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha']), quiet: true), 80, 6)->init();
        $focused = $harness->focused();
        self::assertNotNull($focused);

        self::assertStringNotContainsString('archive all', $harness->screen()->lineAt(5));
        self::assertSame('archive all', array_column($harness->model()->keyHelp(FocusState::on($focused))['Anywhere'], 'description', 'label')['a'] ?? null);
    }

    #[Test]
    public function theFooterLeavesOutAnActionKeyAFocusedFieldTakesAsText(): void
    {
        $screen = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha']), field: TextInput::new()), 80, 6)->screen();

        self::assertStringNotContainsString('archive all', $screen->lineAt(5));
        self::assertStringContainsString('Ctrl+Q quit', $screen->lineAt(5));
    }

    #[Test]
    public function theKeyHelpLeavesOutAnActionKeyAFocusedFieldTakesAsText(): void
    {
        $harness = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha']), field: TextInput::new()), 80, 6)->init();
        $focused = $harness->focused();
        self::assertNotNull($focused);

        $anywhere = array_column($harness->model()->keyHelp(FocusState::on($focused))['Anywhere'], 'description', 'label');

        self::assertNotContains('archive all', $anywhere);
        self::assertSame('keys', $anywhere['Ctrl+K'] ?? null, 'the ? a field would type is left out too');
    }

    #[Test]
    public function runningAnEntryFromAFocusedFieldTypesNothingIntoIt(): void
    {
        $harness = ModelHarness::for(new ArchivingApp(ListView::fromStrings(['alpha']), field: TextInput::new()), 80, 20)->init()
            ->press('ctrl+k')->type('keys')->press('enter');

        self::assertSame('', $harness->model()->field?->value());
    }
}

final readonly class ArchivingApp extends App
{
    public function __construct(public ListView $list, public int $archived = 0, public ?TextInput $field = null, public bool $quiet = false)
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
        return parent::actions()->with('archive', ['a'], static fn (self $app): self => new self($app->list, $app->archived + 1, $app->field, $app->quiet), 'archive all', inFooter: !$this->quiet);
    }
}
