<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Application\NamesItsKeys;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class KeyHelpTest extends TestCase
{
    #[Test]
    public function questionMarkListsTheKeysThatWorkHereAndEscClosesIt(): void
    {
        $harness = self::harness()->press('?');
        $screen = $harness->screen()->plainText();

        self::assertStringContainsString('Keys', $screen);
        self::assertMatchesRegularExpression('/down\s+↓/u', $screen);
        self::assertMatchesRegularExpression('/bottom\s+End/u', $screen, 'a key the footer leaves out');
        self::assertMatchesRegularExpression('/quit\s+Ctrl\+Q\/Ctrl\+C/u', $screen);

        $harness->press('esc');

        self::assertStringNotContainsString('bottom', $harness->screen()->plainText());
    }

    #[Test]
    public function typingFiltersTheKeysAndEnterRunsTheOneUnderTheCursor(): void
    {
        $harness = self::harness()->press('?')->type('bott');

        self::assertSame(['> bott', '', 'Here', '› bottom  End'], \array_slice(self::keyHelpRows($harness), 0, 4));

        $harness->press('enter');

        self::assertStringNotContainsString('Keys', $harness->screen()->plainText());
        self::assertSame('two', $harness->model()->list->cursorItem()?->title());
    }

    #[Test]
    public function ctrlKClosesItWhileTheQuestionMarkIsTyped(): void
    {
        $harness = self::harness()->press('?', '?');

        self::assertSame('> ?', self::keyHelpRows($harness)[0]);

        $harness->press('ctrl+k');

        self::assertStringNotContainsString('Keys', $harness->screen()->plainText());
    }

    #[Test]
    public function inVimModeTheSlashHandsTheKeysToTheFilterAndTheQuestionMarkCloses(): void
    {
        $harness = ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new(), vim: true), 60, 40)->init()->press('?', 'j');

        self::assertSame(['/ search', '', 'Here', 'down  j/↓', '› up  k/↑'], \array_slice(self::keyHelpRows($harness), 0, 5));

        $harness->press('/')->type('quit');
        self::assertSame('/ quit', self::keyHelpRows($harness)[0]);

        $harness->press('enter', '?');
        self::assertStringNotContainsString('Keys', $harness->screen()->plainText());
    }

    #[Test]
    public function lettersThatStartAChordAreTypedIntoTheFilterAtOnce(): void
    {
        $harness = ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new(), vim: true), 60, 40)->init()->press('?', '/')->type('eg');

        self::assertSame('/ eg', self::keyHelpRows($harness)[0]);

        $harness->type('g');

        self::assertSame('/ egg', self::keyHelpRows($harness)[0]);
    }

    #[Test]
    public function aRunningProgramTypesALetterThatStartsAChordIntoTheFilterAtOnce(): void
    {
        $terminal = new VirtualTerminal(60, 40);
        $clock = new TestClock(1000.0);
        $program = Program::new(new KeyHelpApp(self::list(), TextInput::new(), vim: true))->withTerminal($terminal)->withClock($clock);
        $screen = '';

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$screen): void {
            foreach (['?', '/', 'e', 'g'] as $input) {
                $terminal->simulateInput($input);
                $clock->advance(0.01);
                $program->tick();
            }
            $screen = (new ScreenBuffer($terminal->output(), 60, 40))->plainText();
            $terminal->simulateInput("\x11");
        });
        $program->start();

        self::assertStringContainsString('/ eg ', $screen);
    }

    #[Test]
    public function inVimModeItNamesTheVimKeysAndChordsTheListAnswersAndNoOthers(): void
    {
        $screen = ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new(), vim: true), 60, 40)->init()->press('?')->screen()->plainText();

        self::assertMatchesRegularExpression('/down\s+j\/↓/u', $screen);
        self::assertMatchesRegularExpression('/top\s+gg\/Home/u', $screen);
        self::assertDoesNotMatchRegularExpression('/left\s+h │/u', $screen, 'nothing here moves left');
    }

    #[Test]
    public function aKeyIsDescribedInTheWordsOfTheHintsItsWidgetPublishes(): void
    {
        $rows = self::keyHelpRows(ModelHarness::for(new WordedApp(new WordedWidget()), 60, 30)->init()->press('?'));

        self::assertContains('› fill in  Enter', $rows);
    }

    #[Test]
    public function aKeyIsListedOnceInTheClosestGroupThatAnswersIt(): void
    {
        $rows = self::keyHelpRows(ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new(), vim: true), 70, 60)->init()->press('?'));
        $keys = [];
        foreach ($rows as $row) {
            if (preg_match('/\S  (\S+)$/u', $row, $match) === 1) {
                array_push($keys, ...($match[1] === '/' ? ['/'] : explode('/', $match[1])));
            }
        }

        self::assertNotContains('In the footer', $rows);
        self::assertContains('› down  j/↓', $rows);
        self::assertSame(array_values(array_unique($keys)), $keys);
    }

    #[Test]
    public function tabIsListedOnlyWhereASecondStopCanBeReached(): void
    {
        $none = ModelHarness::for(new TextOnlyKeyHelpApp(), 60, 20)->init()->press('?')->screen()->plainText();
        $one = ModelHarness::for(new OneFieldKeyHelpApp(TextInput::new()), 60, 20)->init()->press('ctrl+k')->screen()->plainText();
        $two = self::harness()->press('?')->screen()->plainText();

        self::assertStringContainsString('Keys', $none);
        self::assertStringNotContainsString('Tab', $none);
        self::assertStringContainsString('Keys', $one);
        self::assertStringNotContainsString('Tab', $one);
        self::assertStringContainsString('Tab', $two);
    }

    #[Test]
    public function aFieldTakesTheQuestionMarkAsText(): void
    {
        $harness = self::harness()->press('tab')->type('?');

        self::assertSame('?', $harness->model()->field->value());
        self::assertStringNotContainsString('Keys', $harness->screen()->plainText());
    }

    #[Test]
    public function aRunningProgramRedrawsWhenAShortcutThatChangesNothingClosesTheKeyHelp(): void
    {
        $terminal = new VirtualTerminal(60, 30);
        $clock = new TestClock(1000.0);
        $program = Program::new(new KeyHelpApp(self::list(), TextInput::new()))->withTerminal($terminal)->withClock($clock);
        $shown = [];

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$shown): void {
            foreach (['?', "\x02"] as $input) {
                $terminal->simulateInput($input);
                $clock->advance(1);
                $program->tick();
                $shown[] = str_contains((new ScreenBuffer($terminal->output(), 60, 30))->plainText(), 'Esc close');
            }
            $terminal->simulateInput("\x11");
        });
        $program->start();

        self::assertSame([true, false], $shown);
    }

    #[Test]
    public function aModifiedKeyClosesItAndStillActsSoCtrlQQuits(): void
    {
        $harness = self::harness()->press('?', 'ctrl+q');

        self::assertInstanceOf(QuitCmd::class, $harness->lastCommand());
        self::assertStringNotContainsString('Esc close', $harness->screen()->plainText());
    }

    #[Test]
    public function ctrlKOpensItFromInsideAField(): void
    {
        $harness = self::harness()->press('tab', 'ctrl+k');

        self::assertStringContainsString('Esc close', $harness->screen()->plainText());
        self::assertSame('', $harness->model()->field->value());
    }

    #[Test]
    public function aKeyHelpTallerThanTheScreenScrolls(): void
    {
        $harness = ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new()), 60, 10)->init()->press('?');

        self::assertStringContainsString('Here', $harness->screen()->plainText());

        $harness->press('end');

        self::assertStringNotContainsString('Here', $harness->screen()->plainText());
        self::assertMatchesRegularExpression('/keys\s+\?\/Ctrl\+K/u', $harness->screen()->plainText());
    }

    #[Test]
    public function anAppThatOffersNoKeyHelpLetsTheQuestionMarkThrough(): void
    {
        $harness = ModelHarness::for(new NoKeyHelpApp(self::list()), 60, 30)->init()->press('?');

        self::assertStringNotContainsString('Keys', $harness->screen()->plainText());
    }

    #[Test]
    public function aRunningProgramDrawsTheKeyHelpAsSoonAsQuestionMarkIsPressed(): void
    {
        $terminal = new VirtualTerminal(60, 30);
        $clock = new TestClock(1000.0);
        $program = Program::new(new KeyHelpApp(self::list(), TextInput::new()))->withTerminal($terminal)->withClock($clock);
        $shown = [];

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$shown): void {
            foreach (['', '?', 'x', "\x0b"] as $input) {
                $terminal->simulateInput($input);
                $clock->advance(1);
                $program->tick();
                $shown[] = str_contains((new ScreenBuffer($terminal->output(), 60, 30))->plainText(), 'Keys');
            }
            $terminal->simulateInput("\x11");
        });
        $program->start();

        self::assertSame([false, true, true, false], $shown, 'opens on ?, takes a typed key, closes on Ctrl+K');
    }

    #[Test]
    public function aMountedModelListsTheKeysItAnswersItself(): void
    {
        $screen = ModelHarness::for(new ReportMountingApp(new ReportModel()), 60, 30)->init()->press('?')->screen()->plainText();

        self::assertStringContainsString('Report', $screen);
        self::assertMatchesRegularExpression('/refresh\s+r/u', $screen);
    }

    #[Test]
    public function enterRunsAKeyAMountedModelNames(): void
    {
        $harness = ModelHarness::for(new ReportMountingApp(new ReportModel()), 60, 30)->init()->press('?')->type('refr')->press('enter');

        self::assertSame(1, $harness->model()->report->refreshed);
    }

    #[Test]
    public function aKeyAMountedModelNamesIsNotListedAgainForTheApp(): void
    {
        $harness = ModelHarness::for(new OverlappingKeysApp(), 60, 30)->init();

        $focused = $harness->focused();
        $help = $harness->model()->keyHelp($focused === null ? FocusState::nowhere() : FocusState::on($focused));

        self::assertSame(['r/x' => 'refresh', '/' => 'search', 7 => 'seven'], array_column($help['Report'], 'description', 'label'));
        self::assertSame(['Ctrl+Q/Ctrl+C', 'e', 's'], \array_slice(array_column($help['Anywhere'], 'label'), 0, 3));
    }

    /**
     * @return list<string> the rows inside the box, runs of spaces as two
     */
    private static function keyHelpRows(ModelHarness $harness): array
    {
        $rows = [];
        $left = null;

        foreach (explode("\n", $harness->screen()->plainText()) as $line) {
            $left ??= mb_strpos($line, '╭ Keys') === false ? null : mb_strpos($line, '╭ Keys');
            if ($left === null || str_contains($line, '╭ Keys')) {
                continue;
            }

            $cell = mb_substr($line, (int)$left);
            if (str_starts_with($cell, '╰')) {
                break;
            }

            $rows[] = (string)preg_replace('/\s{2,}/u', '  ', trim(mb_substr($cell, 1, (int)mb_strpos($cell, '│', 1) - 1)));
        }

        return $rows;
    }

    private static function list(): ListView
    {
        return ListView::new([new SimpleListItem('one'), new SimpleListItem('two')]);
    }

    private static function harness(): ModelHarness
    {
        return ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new()), 60, 30)->init();
    }
}

final readonly class TextOnlyKeyHelpApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Text::new('hello');
    }
}

final readonly class OneFieldKeyHelpApp extends CopyableTestApp
{
    public function __construct(public TextInput $field)
    {
    }

    public function view(): Widget
    {
        return $this->bind('field');
    }
}

final readonly class ReportModel implements Reducer, NamesItsKeys
{
    public function __construct(public int $refreshed = 0)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($message instanceof KeyMsg && self::keys()->matches($message, 'refresh') ? new self($this->refreshed + 1) : $this);
    }

    public function ownKeys(): array
    {
        return self::keys()->keyHints(['refresh' => 'refresh']);
    }

    private static function keys(): Keybindings
    {
        return new Keybindings(['refresh' => ['r']]);
    }
}

final readonly class OverlappingKeysModel implements Reducer, NamesItsKeys
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function ownKeys(): array
    {
        return (new Keybindings(['refresh' => ['r'], 'reload' => ['x'], 'search' => ['/'], 'seven' => ['7']]))
            ->keyHints(['refresh' => ['refresh', 'reload'], 'search' => 'search', 'seven' => 'seven']);
    }
}

final readonly class OverlappingKeysApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Mount::named('report', new OverlappingKeysModel(), Text::new('report'), static fn (self $app): self => $app);
    }

    protected function actions(): AppActions
    {
        $nothing = static fn (self $app): self => $app;

        return parent::actions()
            ->with('reload', ['r'], $nothing)
            ->with('find', ['/'], $nothing)
            ->with('export', ['x', 'e'], $nothing)
            ->with('seventh', ['7', 's'], $nothing);
    }
}

final readonly class ReportMountingApp extends CopyableTestApp
{
    public function __construct(public ReportModel $report)
    {
    }

    public function view(): Widget
    {
        return Mount::named('report', $this->report, Text::new('report'), static fn (self $app, ReportModel $report): self => new self($report));
    }
}

final readonly class WordedWidget implements Focusable, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public function __construct(private ?Keybindings $customKeybindings = null, private ?Keybindings $globalKeybindings = null)
    {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([Actions::SELECT => ['enter']]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(['fill in' => Actions::SELECT]);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class WordedApp extends CopyableTestApp
{
    public function __construct(public WordedWidget $widget)
    {
    }

    public function view(): Widget
    {
        return Component::bind('worded', $this->widget, static fn (self $app, WordedWidget $widget): self => new self($widget));
    }
}

final readonly class NoKeyHelpApp extends CopyableTestApp
{
    public function __construct(public ListView $list)
    {
    }

    public function view(): Widget
    {
        return Component::bind('list', $this->list, static fn (self $app, ListView $list): self => new self($list));
    }

    protected function keyHelpKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }
}

final readonly class KeyHelpApp extends CopyableTestApp
{
    public function __construct(public ListView $list, public TextInput $field, private bool $vim = false)
    {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('list', $this->list, static fn (self $app, ListView $list): self => new self($list, $app->field, $app->vim)),
            Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => new self($app->list, $field, $app->vim)),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->vim ? VimMode::keybindings() : Keybindings::empty();
    }
}
