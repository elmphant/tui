<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeyHint;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\DescribesKeys;
use Elmphant\Tui\Runtime\KeyHelpOverlay;
use Elmphant\Tui\Testing\ScreenBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class KeyHelpOverlayTest extends TestCase
{
    #[Test]
    public function theHelpIsABoxInTheMiddleWithAFilterRowEntriesWithTheirKeysOnTheRightAndTheKeysOfTheHelpBelow(): void
    {
        $model = new FakeKeyHelpModel([
            'Here' => [new KeyHint('↑', 'up', KeyPattern::fromString('up')), new KeyHint('Enter', 'open', KeyPattern::fromString('enter'))],
            'Around' => [],
            'App' => [new KeyHint('q', 'quit', KeyPattern::fromString('q'))],
        ]);
        $screen = self::drawn(self::opened($model), $model, 40, 16);

        self::assertSame(
            [
                str_repeat('.', 40),
                str_repeat('.', 40),
                $screen->lineAt(2),
                self::row('>'),
                self::row(''),
                self::row('Here'),
                self::row('› up' . str_repeat(' ', 27) . '↑'),
                self::row('  open' . str_repeat(' ', 21) . 'Enter'),
                self::row(''),
                self::row('App'),
                self::row('  quit' . str_repeat(' ', 25) . 'q'),
                self::row(''),
                self::row('↑/↓ choose  Enter run  Esc close'),
                '..╰' . str_repeat('─', 34) . '╯..',
                str_repeat('.', 40),
                str_repeat('.', 40),
            ],
            array_map($screen->lineAt(...), range(0, 15)),
        );
        self::assertStringStartsWith('..╭', $screen->lineAt(2));
        self::assertStringContainsString('Keys', $screen->lineAt(2));
    }

    #[Test]
    public function typingKeepsTheEntriesThatMatchInABoxOfTheSameSize(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        $before = self::drawn($overlay, $model, 60, 20);

        self::type($overlay, $model, 'cache');
        $after = self::drawn($overlay, $model, 60, 20);

        self::assertSame(
            ['> cache', '', 'Anywhere', '› flush caches Ctrl+R', 'inspect cache configuration', '', '', '', '', '', '', '↑/↓ choose Enter run Esc clear'],
            self::inside($after),
        );
        self::assertSame(\count(self::inside($before)), \count(self::inside($after)));
    }

    #[Test]
    public function enterClosesTheHelpAndHandsOnTheKeyOfTheEntryUnderTheCursor(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'cache');

        self::assertEquals(new KeyMsg('r', ctrl: true), $overlay->handOn(new KeyMsg(Key::Enter), $model, FocusState::nowhere()));
        self::assertFalse($overlay->isOpen());
    }

    #[Test]
    public function theKeyHandedOnKeepsTheScreenOfTheEnterThatRanIt(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        $screen = Rect::fromSize(80, 24);

        $handedOn = $overlay->handOn(new KeyMsg(Key::Enter, screen: $screen), $model, FocusState::nowhere());

        self::assertInstanceOf(KeyMsg::class, $handedOn);
        self::assertSame($screen, $handedOn->screen);
    }

    #[Test]
    public function theArrowsMoveTheCursorOverTheEntriesAndStopAtTheEnds(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);

        foreach ([Key::Down, Key::Down, Key::Up, Key::Up, Key::Up, Key::Down] as $key) {
            $overlay->handOn(new KeyMsg($key), $model, FocusState::nowhere());
        }

        self::assertEquals(new KeyMsg('d'), $overlay->handOn(new KeyMsg(Key::Enter), $model, FocusState::nowhere()));
    }

    #[Test]
    public function enterOnAnEntryWithoutAKeyKeepsTheHelpOpen(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'inspect');

        self::assertNull($overlay->handOn(new KeyMsg(Key::Enter), $model, FocusState::nowhere()));
        self::assertTrue($overlay->isOpen());
        self::assertSame('↑/↓ choose Esc clear', self::filterAndHints(self::drawn($overlay, $model, 60, 20))[1]);
    }

    #[Test]
    public function escapeClearsTheQueryFirstAndThenClosesTheHelp(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'cache');

        self::assertNull($overlay->handOn(new KeyMsg(Key::Escape), $model, FocusState::nowhere()));
        self::assertSame('>', self::inside(self::drawn($overlay, $model, 60, 20))[0]);
        self::assertTrue($overlay->isOpen());

        self::assertNull($overlay->handOn(new KeyMsg(Key::Escape), $model, FocusState::nowhere()));
        self::assertFalse($overlay->isOpen());
    }

    #[Test]
    public function backspaceTakesBackTheLastCharacterAndAPasteIsTyped(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'flé');
        $overlay->handOn(new KeyMsg(Key::Backspace), $model, FocusState::nowhere());
        $overlay->handOn(new PasteMsg("us\nh"), $model, FocusState::nowhere());

        self::assertSame('> flush', self::inside(self::drawn($overlay, $model, 60, 20))[0]);
    }

    #[Test]
    public function aQueryNothingMatchesSaysSo(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'xyz');

        self::assertSame('No key matches "xyz"', self::inside(self::drawn($overlay, $model, 60, 20))[2]);
    }

    #[Test]
    public function theQuestionMarkIsTypedWhileTheFieldTakesTheKeysAndCtrlKStillCloses(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);

        self::assertNull($overlay->handOn(new KeyMsg('?'), $model, FocusState::nowhere()));
        self::assertSame('> ?', self::inside(self::drawn($overlay, $model, 60, 20))[0]);

        self::assertNull($overlay->handOn(new KeyMsg('k', ctrl: true), $model, FocusState::nowhere()));
        self::assertFalse($overlay->isOpen());
    }

    #[Test]
    public function whereTheAppBindsSearchTheListHoldsTheKeysUntilTheSearchKeyHandsThemToTheField(): void
    {
        $model = self::cacheModel(new Keybindings([Actions::SEARCH => ['/'], Actions::CURSOR_DOWN => ['j']]));
        $overlay = self::opened($model);

        self::assertSame('/ search', self::inside(self::drawn($overlay, $model, 60, 20))[0]);
        self::assertSame('↑/↓ choose Enter run / search Esc close', self::inside(self::drawn($overlay, $model, 60, 20))[11]);

        $overlay->handOn(new KeyMsg('j'), $model, FocusState::nowhere());
        self::assertSame('› remove d', self::inside(self::drawn($overlay, $model, 60, 20))[4]);

        self::type($overlay, $model, '/qui');
        self::assertSame(['/ qui', '↑/↓ choose Enter/Esc done'], self::filterAndHints(self::drawn($overlay, $model, 60, 20)));

        self::assertNull($overlay->handOn(new KeyMsg(Key::Enter), $model, FocusState::nowhere()));
        self::assertSame(['/ qui', '↑/↓ choose Enter run / search Esc clear'], self::filterAndHints(self::drawn($overlay, $model, 60, 20)));

        self::assertEquals(new KeyMsg('q', ctrl: true), $overlay->handOn(new KeyMsg(Key::Enter), $model, FocusState::nowhere()));
    }

    #[Test]
    public function whileTheListHoldsTheKeysTheQuestionMarkCloses(): void
    {
        $model = self::cacheModel(new Keybindings([Actions::SEARCH => ['/']]));
        $overlay = self::opened($model);

        self::assertNull($overlay->handOn(new KeyMsg('?'), $model, FocusState::nowhere()));
        self::assertFalse($overlay->isOpen());
    }

    #[Test]
    public function theListMovesOnTheKeysTheAppBindsAndOnNoOthers(): void
    {
        $model = self::cacheModel(new Keybindings([Actions::SEARCH => ['/'], Actions::PAGE_DOWN => ['ctrl+d']]));
        $overlay = self::opened($model);

        self::assertNull($overlay->handOn(new KeyMsg('j'), $model, FocusState::nowhere()));
        self::assertSame('› open Enter', self::inside(self::drawn($overlay, $model, 60, 20))[3], 'j is not bound here');

        self::assertNull($overlay->handOn(new KeyMsg('d', ctrl: true), $model, FocusState::nowhere()));
        self::assertTrue($overlay->isOpen());
        self::assertSame('› quit Ctrl+Q', self::inside(self::drawn($overlay, $model, 60, 20))[9]);
    }

    #[Test]
    public function whileTheFieldTakesTheKeysABoundLetterIsTypedAndABoundChordStillMoves(): void
    {
        $model = self::cacheModel(new Keybindings([Actions::CURSOR_DOWN => ['j', 'ctrl+n']]));
        $overlay = self::opened($model);

        $overlay->handOn(new KeyMsg('j'), $model, FocusState::nowhere());
        self::assertSame('> j', self::inside(self::drawn($overlay, $model, 60, 20))[0]);

        $overlay->handOn(new KeyMsg(Key::Backspace), $model, FocusState::nowhere());
        $overlay->handOn(new KeyMsg('n', ctrl: true), $model, FocusState::nowhere());
        self::assertSame('› remove d', self::inside(self::drawn($overlay, $model, 60, 20))[4]);
    }

    #[Test]
    public function aLongListFollowsTheCursor(): void
    {
        $keys = [];
        foreach (range(1, 30) as $number) {
            $keys[\sprintf('%02d', $number)] = "key $number";
        }
        $model = new FakeKeyHelpModel(['Here' => self::labelled($keys)]);
        $overlay = self::opened($model);

        $overlay->handOn(new KeyMsg(Key::End), $model, FocusState::nowhere());
        $rows = self::inside(self::drawn($overlay, $model, 40, 12));
        self::assertSame('› key 30 30', $rows[\count($rows) - 3]);

        $overlay->handOn(new KeyMsg(Key::Home), $model, FocusState::nowhere());
        self::assertSame(['Here', '› key 1 01'], \array_slice(self::inside(self::drawn($overlay, $model, 40, 12)), 2, 2));

        $overlay->handOn(new KeyMsg(Key::PageDown), $model, FocusState::nowhere());
        self::assertContains('› key 11 11', self::inside(self::drawn($overlay, $model, 40, 12)));
    }

    #[Test]
    public function theCursorStaysOnItsEntryWhenEntriesAppearAboveIt(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        $overlay->handOn(new KeyMsg(Key::Down), $model, FocusState::nowhere());

        $model->groups = ['Here' => [new KeyHint('x', 'export'), ...$model->groups['Here']], 'Anywhere' => $model->groups['Anywhere']];

        self::assertContains('› remove d', self::inside(self::drawn($overlay, $model, 60, 20)));
    }

    #[Test]
    public function theWheelMovesTheCursorAndAPressIsTaken(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);

        self::assertNull($overlay->handOn(self::mouse(MouseAction::WheelDown), $model, FocusState::nowhere()));
        self::assertNull($overlay->handOn(self::mouse(MouseAction::Press), $model, FocusState::nowhere()));

        self::assertEquals(new KeyMsg('d'), $overlay->handOn(new KeyMsg(Key::Enter), $model, FocusState::nowhere()));
    }

    #[Test]
    public function aQueryWiderThanTheBoxShowsItsEndWhereTheTypingIs(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'inspect cache configuration');

        self::assertSame('> … configuration', self::inside(self::drawn($overlay, $model, 24, 20))[0]);
    }

    #[Test]
    public function aNarrowBoxCutsTheDescriptionAndKeepsTheKey(): void
    {
        $model = self::cacheModel();

        self::assertSame('flush c… Ctrl+R', self::inside(self::drawn(self::opened($model), $model, 24, 20))[7]);
    }

    #[Test]
    public function aScreenTooShortForTheHelpGrowsDownwardsByJustTheRowsItNeeds(): void
    {
        $model = new FakeKeyHelpModel(['Here' => self::labelled(['q' => 'quit'])]);
        $frame = new Frame(40, 30);

        self::opened($model)->drawOver($frame, new RenderContext(Theme::dark()), $model, FocusState::nowhere(), Rect::fromSize(40, 2));

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertStringContainsString('Keys', $screen->lineAt(1));
        self::assertStringContainsString('quit', $screen->lineAt(5));
        self::assertStringContainsString('╰', $screen->lineAt(8));
        self::assertSame(9, $frame->drawnHeight());
    }

    #[Test]
    public function aClosedHelpDrawsNothing(): void
    {
        $model = self::cacheModel();
        $frame = new Frame(10, 4);
        (new KeyHelpOverlay())->drawOver($frame, new RenderContext(Theme::dark()), $model, FocusState::nowhere(), $frame->area());

        self::assertSame('', trim(ScreenBuffer::fromFrame($frame)->plainText()));
    }

    #[Test]
    public function aClosedHelpTakesTheKeyThatOpensItAndLetsEveryOtherMessageThrough(): void
    {
        $model = self::cacheModel();
        $overlay = new KeyHelpOverlay();

        foreach ([new KeyMsg('x'), self::mouse(MouseAction::Press), new PasteMsg('pasted')] as $message) {
            self::assertSame($message, $overlay->handOn($message, $model, FocusState::nowhere()));
        }
        $question = new KeyMsg('?');
        self::assertSame($question, $overlay->handOn($question, new stdClass(), FocusState::nowhere()), 'a model with no keys to describe has no help');
        self::assertFalse($overlay->isOpen());
        self::assertNull($overlay->handOn($question, $model, FocusState::nowhere()));
        self::assertTrue($overlay->isOpen());
    }

    #[Test]
    public function reopenedTheHelpStartsEmptyAtTheTop(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        self::type($overlay, $model, 'cache');
        $overlay->handOn(new KeyMsg('k', ctrl: true), $model, FocusState::nowhere());
        $overlay->handOn(new KeyMsg('k', ctrl: true), $model, FocusState::nowhere());

        self::assertSame(['>', '', 'Here', '› open Enter'], \array_slice(self::inside(self::drawn($overlay, $model, 60, 20)), 0, 4));
    }

    #[Test]
    public function anotherShortcutClosesItAndStillActs(): void
    {
        $model = self::cacheModel();
        $overlay = self::opened($model);
        $quit = new KeyMsg('q', ctrl: true);

        self::assertSame($quit, $overlay->handOn($quit, $model, FocusState::nowhere()));
        self::assertFalse($overlay->isOpen());
    }

    #[Test]
    public function theSmallestScreenStillShowsTheBox(): void
    {
        $model = self::cacheModel();

        self::assertSame('.╭─╮.', self::drawn(self::opened($model), $model, 5, 5)->lineAt(1));
    }

    #[Test]
    public function aScreenTooNarrowForTheBoxShowsNothing(): void
    {
        $model = self::cacheModel();

        self::assertSame(str_repeat("....\n", 9) . '....', self::drawn(self::opened($model), $model, 4, 10)->plainText());
    }

    private static function cacheModel(?Keybindings $search = null): FakeKeyHelpModel
    {
        return new FakeKeyHelpModel(
            [
                'Here' => [
                    new KeyHint('Enter', 'open', KeyPattern::fromString('enter')),
                    new KeyHint('d', 'remove', KeyPattern::fromString('d')),
                ],
                'Anywhere' => [
                    new KeyHint('Ctrl+R', 'flush caches', KeyPattern::fromString('ctrl+r')),
                    new KeyHint('', 'inspect cache configuration'),
                    new KeyHint('Ctrl+Q', 'quit', KeyPattern::fromString('ctrl+q')),
                ],
            ],
            $search ?? Keybindings::empty(),
        );
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @return list<KeyHint>
     */
    private static function labelled(array $keys): array
    {
        return array_map(
            static fn (int|string $label, string $description): KeyHint => new KeyHint((string)$label, $description),
            array_keys($keys),
            $keys,
        );
    }

    private static function opened(FakeKeyHelpModel $model): KeyHelpOverlay
    {
        $overlay = new KeyHelpOverlay();
        $overlay->handOn(new KeyMsg('k', ctrl: true), $model, FocusState::nowhere());

        return $overlay;
    }

    private static function type(KeyHelpOverlay $overlay, FakeKeyHelpModel $model, string $text): void
    {
        foreach (mb_str_split($text) as $character) {
            $overlay->handOn(new KeyMsg($character), $model, FocusState::nowhere());
        }
    }

    private static function drawn(KeyHelpOverlay $overlay, DescribesKeys $model, int $width, int $height): ScreenBuffer
    {
        $frame = new Frame($width, $height);
        for ($row = 0; $row < $height; ++$row) {
            $frame->putString(0, $row, str_repeat('.', $width));
        }

        $overlay->drawOver($frame, new RenderContext(Theme::dark()), $model, FocusState::nowhere(), $frame->area());

        return ScreenBuffer::fromFrame($frame);
    }

    /**
     * @return list<string> the rows inside the box, runs of spaces as one
     */
    private static function inside(ScreenBuffer $screen): array
    {
        $rows = [];
        foreach (explode("\n", $screen->plainText()) as $line) {
            if (preg_match('/│(.*)│/u', $line, $match) === 1) {
                $rows[] = trim((string)preg_replace('/\s+/u', ' ', $match[1]));
            }
        }

        return $rows;
    }

    /**
     * @return array{string, string}
     */
    private static function filterAndHints(ScreenBuffer $screen): array
    {
        $rows = self::inside($screen);

        return [$rows[0], $rows[\count($rows) - 1]];
    }

    private static function row(string $text): string
    {
        return '..│ ' . $text . str_repeat(' ', 32 - mb_strwidth($text)) . ' │..';
    }

    private static function mouse(MouseAction $action): MouseMsg
    {
        return new MouseMsg(20, 5, $action, MouseButton::Left);
    }
}

final class FakeKeyHelpModel implements DescribesKeys
{
    /**
     * @param array<string, list<KeyHint>> $groups
     */
    public function __construct(public array $groups, private readonly Keybindings $search = new Keybindings())
    {
    }

    public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool
    {
        return $key->key === '?' || ($key->ctrl && $key->key === 'k');
    }

    public function keyHelp(FocusState $focus): array
    {
        return $this->groups;
    }

    public function keyHelpKeys(): Keybindings
    {
        return $this->search;
    }
}
