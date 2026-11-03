<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownTest extends TestCase
{
    #[Test]
    public function aClosedDropdownNamesOnlyTheKeyThatOpensIt(): void
    {
        self::assertSame(
            ['Enter' => 'open'],
            Dropdown::new(Select::new(['a']))->hints(),
        );
    }

    #[Test]
    public function anOpenDropdownNamesTheKeyThatShutsIt(): void
    {
        $open = Dropdown::new(Select::new(['a']))
                        ->update(new KeyMsg(Key::Enter), Rect::fromSize(20, 1))
            ->model;

        self::assertInstanceOf(Dropdown::class, $open);
        self::assertTrue($open->isOpen(), 'Enter must have opened it');
        self::assertSame(['Esc' => 'close'], $open->hints());
    }

    #[Test]
    public function givenMoreThanOneRowTheListOpensRightUnderTheRowItDraws(): void
    {
        $screen = WidgetHarness::for(Dropdown::new(Select::new(['Inbox', 'Work', 'Home'])), width: 40, height: 12)
            ->press('enter')
            ->screen();

        self::assertSame(0, $screen->rowOf('▾'));
        self::assertSame(1, $screen->rowOf('╭'));
        self::assertNotNull($screen->find('Work'));
    }

    #[Test]
    public function aHostCanOpenAndCloseTheListWithoutAKey(): void
    {
        $open = Dropdown::new(Select::new(['Inbox', 'Work']))->withOpen();

        self::assertNotNull(WidgetHarness::for($open, width: 40, height: 12)->screen()->find('Work'));
        self::assertFalse($open->withOpen(false)->isOpen());
    }

    #[Test]
    public function anOpenDropdownStaysOpenWhenItsContentIsReplaced(): void
    {
        $open = Dropdown::new(Select::new(['Inbox', 'Work']))->withOpen();

        $reloaded = $open->withContent(Select::new(['Inbox', 'Work', 'Home']));

        self::assertTrue($reloaded->isOpen());
        self::assertNotNull(WidgetHarness::for($reloaded, width: 40, height: 12)->screen()->find('Home'));
    }

    #[Test]
    public function holdsAWidgetAndLetsItAnswerForItself(): void
    {
        $dropdown = Dropdown::new(Select::new(['json', 'yaml'])->withEmptyCaption('choose a format'));

        self::assertSame('choose a format', $dropdown->content()->caption());
        self::assertStringContainsString('choose a format', self::screen($dropdown)->lineAt(0));
    }

    #[Test]
    public function closedItShowsWhatItHoldsOnOneRow(): void
    {
        $dropdown = Dropdown::new(Select::new(['json', 'yaml'])->activated('yaml'));

        self::assertSame(1, $dropdown->sizing()->rows->min);
        self::assertStringContainsString('yaml', self::screen($dropdown)->lineAt(0));
    }

    #[Test]
    public function theMarkerPrecedesTheValueOnTheClosedRow(): void
    {
        $dropdown = Dropdown::new(Select::new(['json'])->activated('json'));

        self::assertStringStartsWith('▾ json', self::screen($dropdown)->lineAt(0));
    }

    #[Test]
    public function theClosedRowReadsWhateverItHoldsSaysItIs(): void
    {
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four']);

        self::assertStringContainsString('0 of 4', self::screen(Dropdown::new($group))->lineAt(0));
        self::assertStringContainsString(
            '2 of 4',
            self::screen(Dropdown::new($group->activated('one')->activated('two')))->lineAt(0),
            'the caption is derived, so it cannot hold a stale copy',
        );
    }

    #[Test]
    public function spaceOpensAFloatingListBelowTheRow(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        self::assertTrue($opened->isOpen());
        self::assertStringContainsString('yaml', self::screen($opened, focused: true)->plainText(), 'The popup drew');
    }

    #[Test]
    public function enterOpensAClosedDropdown(): void
    {
        $opened = Dropdown::new(Select::new(['json', 'yaml']))
                        ->update(new KeyMsg(Key::Enter), self::anchor())->model;

        self::assertTrue($opened->isOpen());
    }

    #[Test]
    public function enterOnContentThatFinishesItsChoiceClosesThePopup(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        $moved = $opened->update(new KeyMsg(Key::Down), self::anchor())->model;
        $picked = $moved->update(new KeyMsg(Key::Enter), self::anchor())->model;

        self::assertFalse($picked->isOpen());
        self::assertSame('yaml', $picked->content()->caption());
    }

    #[Test]
    public function tickingABoxIsNotFinishingSoTheListStaysUp(): void
    {
        $opened = self::opened(Dropdown::new(CheckboxGroup::new(['one', 'two', 'three'])));

        $ticked = $opened->update(new KeyMsg(Key::Enter), self::anchor())->model;

        self::assertSame(['one'], $ticked->content()->checkedValues());
        self::assertTrue($ticked->isOpen(), 'a checkbox group does not commit, so its popup stays open');
    }

    #[Test]
    public function theFirstEscapeClearsAQueryAndTheSecondClosesThePopup(): void
    {
        $opened = self::opened(Dropdown::new(self::listOf(['json', 'yaml'])));

        $typed = $opened->update(new KeyMsg('y'), self::anchor())->model;
        $cleared = $typed->update(new KeyMsg(Key::Escape), self::anchor())->model;

        self::assertTrue($cleared->isOpen(), 'the list had its own thing to cancel, so it keeps the escape');
        self::assertSame('', $cleared->content()->query());

        $closed = $cleared->update(new KeyMsg(Key::Escape), self::anchor())->model;

        self::assertFalse($closed->isOpen(), 'with nothing left to clear, the escape closes the popup');
    }

    #[Test]
    public function escapeClosesWithoutChangingTheValue(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])->activated('json')));

        $closed = $opened->update(new KeyMsg(Key::Escape), self::anchor())->model;

        self::assertFalse($closed->isOpen());
        self::assertSame('json', $closed->content()->caption(), 'escaping is not choosing');
    }

    #[Test]
    public function aRebindingReachesWhatTheDropdownHolds(): void
    {
        $bindings = new Keybindings([Actions::CURSOR_DOWN => ['j']]);
        $dropdown = self::opened(Dropdown::new(Select::new(['one', 'two'])))
            ->withGlobalKeybindings($bindings);

        $moved = $dropdown->update(new KeyMsg('j'), self::anchor())->model;

        self::assertSame(1, $moved->content()->cursor(), 'the cursor moved, so the binding got through');
    }

    #[Test]
    public function rightNoLongerOpensTheDropdown(): void
    {
        $answered = Dropdown::new(Select::new(['json', 'yaml']))
                        ->update(new KeyMsg(Key::Right), self::anchor());

        self::assertFalse($answered->model->isOpen());
    }

    #[Test]
    public function anUnboundControlKeyWhileOpenIsIgnoredWithoutError(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        $answered = $opened->update(new KeyMsg('z', ctrl: true), self::anchor());

        self::assertTrue($answered->model->isOpen());
        self::assertNull($answered->command);
    }

    #[Test]
    public function aCaptionStandingInForAChoiceIsDrawnAsThePromptItIs(): void
    {
        $prompt = Dropdown::new(Select::new(['json'])->withEmptyCaption('pick one'));
        $chosen = Dropdown::new(Select::new(['json'])->activated('json'));
        $theme = Theme::dark();

        self::assertSame(
            $theme->dim->foregroundValue(),
            self::foregroundOfFirstCell(self::frameFor($prompt, $theme)),
            'a prompt is dim however much it has to say',
        );
        self::assertSame(
            $theme->base->foregroundValue(),
            self::foregroundOfFirstCell(self::frameFor($chosen, $theme)),
            'and a value it named is not',
        );
    }

    #[Test]
    public function aFocusedEmptyRowIsMarkedLikeAnythingElseUnderTheCursor(): void
    {
        $dropdown = Dropdown::new(Select::new(['json']));
        $theme = Theme::dark();

        self::assertSame(
            $theme->cursorStyle->foregroundValue(),
            self::foregroundOfFirstCell(self::frameFor($dropdown, $theme, focused: true)),
            'accent means a value was chosen, so focus cannot borrow it',
        );
    }

    #[Test]
    public function aFocusedRowHoldingAValueIsMarkedTheSameAndNamesItInTheCaption(): void
    {
        $dropdown = Dropdown::new(Select::new(['json'])->activated('json'));
        $theme = Theme::dark();

        self::assertSame(
            $theme->cursorStyle->foregroundValue(),
            self::foregroundOfFirstCell(self::frameFor($dropdown, $theme, focused: true)),
            'the mark says where the keys are',
        );
        self::assertStringContainsString('json', $dropdown->caption(), 'and the caption says what is held');
    }

    #[Test]
    public function anAreaExactlyOneCellWideStillDrawsTheMarker(): void
    {
        $frame = new Frame(10, 3);
        RootPass::draw(
            RenderPass::new(Theme::dark()),
            Dropdown::new(Select::new(['json'])->activated('json')),
            $frame,
            new Rect(0, 0, 1, 1),
        );

        self::assertSame('▾', ScreenBuffer::fromFrame($frame)->textAt(0, 0, 1));
    }

    #[Test]
    public function aZeroHeightAreaDrawsNothing(): void
    {
        $frame = new Frame(10, 3);
        RootPass::draw(
            RenderPass::new(Theme::dark()),
            Dropdown::new(Select::new(['json'])->activated('json')),
            $frame,
            new Rect(0, 0, 10, 0),
        );

        self::assertSame('', trim(ScreenBuffer::fromFrame($frame)->plainText()));
    }

    #[Test]
    public function anOpenDropdownDrawsExactlyOnePopupPerFrame(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        self::assertSame(
            1,
            substr_count(self::screen($opened, focused: true)->plainText(), '╭'),
            'the drain must not redraw and requeue the float',
        );
    }

    #[Test]
    public function anOpenDropdownNeverRedrawsItsClosedRowBelowTheAnchor(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        self::assertStringNotContainsString(
            '▾',
            self::popupBody(self::frameFor($opened, Theme::dark())),
            'the marker belongs to the anchor row alone',
        );
    }

    #[Test]
    public function tellsAHostWhatItsClosedRowReadsSoTheHostCanMeasureIt(): void
    {
        $dropdown = Dropdown::new(Select::new(['json', 'yaml'])->withEmptyCaption('none yet'));

        self::assertSame('▾ none yet', $dropdown->caption());
        self::assertStringContainsString(
            $dropdown->caption(),
            self::screen($dropdown)->plainText(),
            'and that is exactly what it draws',
        );
    }

    /**
     * Hide a popup when tree focus moves away, even if the dropdown's stored open flag remains true.
     */
    #[Test]
    public function anOpenListGoesWhenTheKeyboardMovesToAnotherComponent(): void
    {
        $driven = ModelHarness::for(new PickerBesideAFieldApp(TextInput::new()), 30, 12)->initialize();

        $driven->dispatch(new KeyMsg(' '));
        self::assertStringContainsString(
            'yaml',
            $driven->renderScreen()->plainText(),
            'precondition: the list is open',
        );

        $driven->dispatch(new KeyMsg(Key::Tab));

        self::assertStringNotContainsString('yaml', $driven->renderScreen()->plainText());
    }

    #[Test]
    public function pageDownInAnOpenListMovesByThePageThePopupShows(): void
    {
        $items = array_map(static fn (int $index): SimpleListItem => new SimpleListItem("item{$index}"), range(0, 29));
        $opened = self::opened(Dropdown::new(FilterableList::new($items)));

        $paged = $opened->update((new KeyMsg(Key::PageDown))->withScreen(new Rect(0, 0, 30, 14)), self::anchor())->model;
        $content = $paged->content();

        self::assertInstanceOf(FilterableList::class, $content);
        self::assertGreaterThan(1, $content->cursorSourceIndex());
    }

    #[Test]
    public function aPasteIntoAnOpenFilterListFiltersIt(): void
    {
        $opened = self::opened(Dropdown::new(FilterableList::new([new SimpleListItem('alpha'), new SimpleListItem('beta')])));

        $content = $opened->update(new PasteMsg('be'), self::anchor())->model->content();

        self::assertInstanceOf(FilterableList::class, $content);
        self::assertSame('be', $content->query());
    }

    /**
     * Inspect only popup rows; the trigger always shows the selection and could falsely satisfy a visibility assertion.
     */
    private static function popupBody(Frame $frame): string
    {
        $screen = ScreenBuffer::fromFrame($frame);
        $rows = [];

        for ($row = 1; $row < $frame->height; ++$row) {
            $rows[] = $screen->lineAt($row);
        }

        return implode("\n", $rows);
    }

    /**
     * @param list<string> $titles
     */
    private static function listOf(array $titles): FilterableList
    {
        return FilterableList::new(array_map(
            static fn (string $title): SimpleListItem => new SimpleListItem($title),
            $titles,
        ));
    }

    private static function anchor(): Rect
    {
        return new Rect(0, 0, 20, 1);
    }

    private static function frameFor(Dropdown $dropdown, Theme $theme, bool $focused = false): Frame
    {
        $frame = new Frame(30, 6);
        RootPass::draw(self::pass($theme, $focused), $dropdown, $frame, self::anchor());

        return $frame;
    }

    private static function foregroundOfFirstCell(Frame $frame): int
    {
        return ScreenBuffer::fromFrame($frame)->styleAt(0, 0)->foregroundValue();
    }

    private static function screen(Dropdown $dropdown, bool $focused = false): ScreenBuffer
    {
        $frame = new Frame(30, 12);
        RootPass::draw(self::pass(Theme::dark(), $focused), $dropdown, $frame, self::anchor());

        return ScreenBuffer::fromFrame($frame);
    }

    private static function pass(Theme $theme, bool $focused): RenderPass
    {
        return RenderPass::new($theme, focus: $focused ? FocusPath::here() : null);
    }

    private static function opened(Dropdown $dropdown): Dropdown
    {
        return $dropdown->update(new KeyMsg(' '), self::anchor())->model;
    }
}

final readonly class PickerBesideAFieldApp extends App
{
    public function __construct(
        public TextInput $field,
        public ?Dropdown $picker = null,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'picker',
                $this->picker ?? Dropdown::new(Select::new(['json', 'yaml'])),
                static fn (self $app, Dropdown $picker): self => $app->cloneWith(picker: $picker),
            ),
            Component::bind(
                'field',
                $this->field,
                static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field),
            ),
        );
    }
}
