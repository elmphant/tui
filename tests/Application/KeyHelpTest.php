<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

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
        $screen = $harness->renderScreen()->plainText();

        self::assertStringContainsString('Keys', $screen);
        self::assertMatchesRegularExpression('/↓\s+down/u', $screen);
        self::assertMatchesRegularExpression('/End\s+bottom/u', $screen, 'a key the footer leaves out');
        self::assertMatchesRegularExpression('/Ctrl\+Q\/Ctrl\+C\s+quit/u', $screen);

        $harness->press('esc');

        self::assertStringNotContainsString('bottom', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function questionMarkClosesItToo(): void
    {
        $harness = self::harness()->press('?', '?');

        self::assertStringNotContainsString('Keys', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function inVimModeItNamesTheVimKeysAndChordsTheListAnswersAndNoOthers(): void
    {
        $screen = ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new(), vim: true), 60, 40)->initialize()->press('?')->renderScreen()->plainText();

        self::assertMatchesRegularExpression('/j\/↓\s+down/u', $screen);
        self::assertMatchesRegularExpression('/gg\/Home\s+top/u', $screen);
        self::assertDoesNotMatchRegularExpression('/│ h\s+left/u', $screen, 'nothing here moves left');
    }

    #[Test]
    public function aKeyIsDescribedInTheWordsOfTheHintsItsWidgetPublishes(): void
    {
        $rows = self::keyHelpRows(ModelHarness::for(new WordedApp(new WordedWidget()), 60, 30)->initialize()->press('?'));

        self::assertContains('Enter  fill in', array_map(static fn (string $row): string => preg_replace('/\s{2,}/u', '  ', $row) ?? $row, $rows));
    }

    #[Test]
    public function aKeyIsListedOnceInTheClosestGroupThatAnswersIt(): void
    {
        $rows = self::keyHelpRows(ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new(), vim: true), 70, 60)->initialize()->press('?'));
        $keys = [];
        foreach ($rows as $row) {
            if (preg_match('/^(\S+)\s{2,}\S/u', $row, $match) === 1) {
                array_push($keys, ...($match[1] === '/' ? ['/'] : explode('/', $match[1])));
            }
        }

        self::assertNotContains('In the footer', $rows);
        self::assertContains('j/↓                  down', $rows);
        self::assertSame(array_values(array_unique($keys)), $keys);
    }

    #[Test]
    public function aFieldTakesTheQuestionMarkAsText(): void
    {
        $harness = self::harness()->press('tab')->type('?');

        self::assertSame('?', $harness->model()->field->value());
        self::assertStringNotContainsString('Keys', $harness->renderScreen()->plainText());
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
                $shown[] = str_contains((new ScreenBuffer($terminal->output(), 60, 30))->plainText(), 'Esc to close');
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
        self::assertStringNotContainsString('Esc to close', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function ctrlKOpensItFromInsideAField(): void
    {
        $harness = self::harness()->press('tab', 'ctrl+k');

        self::assertStringContainsString('Esc to close', $harness->renderScreen()->plainText());
        self::assertSame('', $harness->model()->field->value());
    }

    #[Test]
    public function theFooterHintNamesTheKeyThatWorksEverywhere(): void
    {
        self::assertSame(['Ctrl+K' => 'keys'], self::harness()->model()->keyHelpHints());
    }

    #[Test]
    public function aKeyHelpTallerThanTheScreenScrolls(): void
    {
        $harness = ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new()), 60, 10)->initialize()->press('?');

        self::assertStringContainsString('Here', $harness->renderScreen()->plainText());

        $harness->press('end');

        self::assertStringNotContainsString('Here', $harness->renderScreen()->plainText());
        self::assertMatchesRegularExpression('/\?\/Ctrl\+K\s+keys/u', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function anAppThatOffersNoKeyHelpLetsTheQuestionMarkThrough(): void
    {
        $harness = ModelHarness::for(new NoKeyHelpApp(self::list()), 60, 30)->initialize()->press('?');

        self::assertStringNotContainsString('Keys', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function aRunningProgramDrawsTheKeyHelpAsSoonAsQuestionMarkIsPressed(): void
    {
        $terminal = new VirtualTerminal(60, 30);
        $clock = new TestClock(1000.0);
        $program = Program::new(new KeyHelpApp(self::list(), TextInput::new()))->withTerminal($terminal)->withClock($clock);
        $shown = [];

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$shown): void {
            foreach (['', '?', "\r"] as $input) {
                $terminal->simulateInput($input);
                $clock->advance(1);
                $program->tick();
                $shown[] = str_contains((new ScreenBuffer($terminal->output(), 60, 30))->plainText(), 'Esc to close');
            }
            $terminal->simulateInput('?');
            $clock->advance(1);
            $program->tick();
            $shown[] = str_contains((new ScreenBuffer($terminal->output(), 60, 30))->plainText(), 'Esc to close');
            $terminal->simulateInput("\x11");
        });
        $program->start();

        self::assertSame([false, true, true, false], $shown, 'opens on ?, swallows Enter, closes on ?');
    }

    #[Test]
    public function aMountedModelListsTheKeysItAnswersItself(): void
    {
        $screen = ModelHarness::for(new ReportMountingApp(new ReportModel()), 60, 30)->initialize()->press('?')->renderScreen()->plainText();

        self::assertStringContainsString('Report', $screen);
        self::assertMatchesRegularExpression('/r\s+refresh/u', $screen);
    }

    #[Test]
    public function aKeyAMountedModelNamesIsNotListedAgainForTheApp(): void
    {
        $harness = ModelHarness::for(new OverlappingKeysApp(), 60, 30)->initialize();

        $focused = $harness->focused();
        $help = $harness->model()->keyHelp($focused === null ? FocusState::nowhere() : FocusState::on($focused));

        self::assertSame(['r/x' => 'refresh', '/' => 'search', 7 => 'seven'], $help['Report']);
        self::assertSame(['e', 's'], \array_slice(array_map(strval(...), array_keys($help['Anywhere'])), 0, 2));
    }

    /**
     * @return list<string> the rows inside the key help's box, trimmed
     */
    private static function keyHelpRows(ModelHarness $harness): array
    {
        $rows = [];
        $left = null;

        foreach (explode("\n", $harness->renderScreen()->plainText()) as $line) {
            $left ??= mb_strpos($line, '╭ Keys') === false ? null : mb_strpos($line, '╭ Keys');
            if ($left === null || str_contains($line, '╭ Keys')) {
                continue;
            }

            $cell = mb_substr($line, (int)$left);
            if (str_starts_with($cell, '╰')) {
                break;
            }

            $rows[] = trim(mb_substr($cell, 1, (int)mb_strpos($cell, '│', 1) - 1));
        }

        return $rows;
    }

    private static function list(): ListView
    {
        return ListView::new([new SimpleListItem('one'), new SimpleListItem('two')]);
    }

    private static function harness(): ModelHarness
    {
        return ModelHarness::for(new KeyHelpApp(self::list(), TextInput::new()), 60, 30)->initialize();
    }
}

final readonly class ReportModel implements Reducer, NamesItsKeys
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function ownKeys(): array
    {
        return (new Keybindings(['refresh' => ['r']]))->hints(['refresh' => 'refresh']);
    }
}

final readonly class OverlappingKeysModel implements Reducer, NamesItsKeys
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function ownKeys(): array
    {
        return ['r/x' => 'refresh', '/' => 'search', '7' => 'seven'];
    }
}

final readonly class OverlappingKeysApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Mount::named('report', new OverlappingKeysModel(), Text::new('report'), static fn (self $app): self => $app);
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings(['reload' => ['r'], 'find' => ['/'], 'export' => ['x', 'e'], 'seventh' => ['7', 's']]);
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
        return Layout::vertical(
            Component::bind('list', $this->list, static fn (self $app, ListView $list): self => new self($list, $app->field, $app->vim)),
            Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => new self($app->list, $field, $app->vim)),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->vim ? VimMode::keybindings() : Keybindings::empty();
    }
}
