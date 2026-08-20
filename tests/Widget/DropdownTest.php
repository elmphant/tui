<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\CheckboxGroup;
use Elmphant\Tui\Widget\Dropdown;
use Elmphant\Tui\Widget\FilterableList;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\SimpleListItem;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownTest extends TestCase
{
    #[Test]
    public function holdsAWidgetAndLetsItAnswerForItself(): void
    {
        $dropdown = Dropdown::new(Select::new(['json', 'yaml'])->withEmptyCaption('choose a format'));

        self::assertSame('choose a format', $dropdown->content()->caption());
        self::assertStringContainsString('choose a format', self::screen($dropdown)->getLine(0));
    }

    #[Test]
    public function closedItShowsWhatItHoldsOnOneRow(): void
    {
        $dropdown = Dropdown::new(Select::new(['json', 'yaml'])->activated('yaml'));

        self::assertSame(1, $dropdown->sizing()->rows->min);
        self::assertStringContainsString('yaml', self::screen($dropdown)->getLine(0));
    }

    #[Test]
    public function theMarkerPrecedesTheValueOnTheClosedRow(): void
    {
        $dropdown = Dropdown::new(Select::new(['json'])->activated('json'));

        self::assertStringStartsWith('▾ json', self::screen($dropdown)->getLine(0));
    }

    #[Test]
    public function theClosedRowReadsWhateverItHoldsSaysItIs(): void
    {
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four']);

        self::assertStringContainsString('0 of 4', self::screen(Dropdown::new($group))->getLine(0));
        self::assertStringContainsString(
            '2 of 4',
            self::screen(Dropdown::new($group->activated('one')->activated('two')))->getLine(0),
            'the caption is derived, so it cannot hold a stale copy',
        );
    }

    #[Test]
    public function spaceOpensAFloatingListBelowTheRow(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        self::assertTrue($opened->isOpen());
        self::assertStringContainsString('yaml', self::screen($opened)->getPlainText(), 'The popup drew');
    }

    #[Test]
    public function enterOpensAClosedDropdown(): void
    {
        $opened = Dropdown::new(Select::new(['json', 'yaml']))
            ->withFocus(true)
            ->update(new KeyMsg(Key::Enter), self::anchor())->model;

        self::assertTrue($opened->isOpen());
    }

    #[Test]
    public function openingHandsTheContentTheKeyboard(): void
    {
        $closed = Dropdown::new(Select::new(['json', 'yaml']));

        self::assertFalse($closed->content()->isFocused(), 'content is built unfocused, as every widget is');
        self::assertTrue(self::opened($closed)->content()->isFocused(), 'or it would never answer a key');
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
        $bindings = new Keybindings([Actions::FOCUS_NEXT => ['j']]);
        $dropdown = self::opened(Dropdown::new(Select::new(['one', 'two'])))
            ->withGlobalKeybindings($bindings);

        $moved = $dropdown->update(new KeyMsg('j'), self::anchor())->model;

        self::assertSame(1, $moved->content()->cursor(), 'the cursor moved, so the binding got through');
    }

    #[Test]
    public function rightNoLongerOpensTheDropdown(): void
    {
        $answered = Dropdown::new(Select::new(['json', 'yaml']))
            ->withFocus(true)
            ->update(new KeyMsg(Key::Right), self::anchor());

        self::assertFalse($answered->model->isOpen());
    }

    #[Test]
    public function anUnfocusedDropdownIgnoresTheToggleKey(): void
    {
        $answered = Dropdown::new(Select::new(['json']))->update(new KeyMsg(' '), self::anchor());

        self::assertFalse($answered->model->isOpen());
    }

    #[Test]
    public function isFocusedDefaultsToFalse(): void
    {
        self::assertFalse(Dropdown::new(Select::new(['json']))->isFocused());
    }

    #[Test]
    public function losingFocusClosesAnOpenDropdown(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        self::assertFalse($opened->withFocus(false)->isOpen());
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
        $dropdown = Dropdown::new(Select::new(['json']))->withFocus(true);
        $theme = Theme::dark();

        self::assertSame(
            $theme->cursorStyle->foregroundValue(),
            self::foregroundOfFirstCell(self::frameFor($dropdown, $theme)),
            'accent means a value was chosen, so focus cannot borrow it',
        );
    }

    #[Test]
    public function aFocusedRowHoldingAValueIsMarkedTheSameAndNamesItInTheCaption(): void
    {
        $dropdown = Dropdown::new(Select::new(['json'])->activated('json'))->withFocus(true);
        $theme = Theme::dark();

        self::assertSame(
            $theme->cursorStyle->foregroundValue(),
            self::foregroundOfFirstCell(self::frameFor($dropdown, $theme)),
            'the mark says where the keys are',
        );
        self::assertStringContainsString('json', $dropdown->caption(), 'and the caption says what is held');
    }

    #[Test]
    public function anAreaExactlyOneCellWideStillDrawsTheMarker(): void
    {
        $frame = new Frame(10, 3);
        (new RenderContext(Theme::dark()))->renderRoot(
            Dropdown::new(Select::new(['json'])->activated('json')),
            $frame,
            new Rect(0, 0, 1, 1),
        );

        self::assertSame('▾', ScreenBuffer::fromFrame($frame)->getTextAt(0, 0, 1));
    }

    #[Test]
    public function aZeroHeightAreaDrawsNothing(): void
    {
        $frame = new Frame(10, 3);
        (new RenderContext(Theme::dark()))->renderRoot(
            Dropdown::new(Select::new(['json'])->activated('json')),
            $frame,
            new Rect(0, 0, 10, 0),
        );

        self::assertSame('', trim(ScreenBuffer::fromFrame($frame)->getPlainText()));
    }

    #[Test]
    public function anOpenDropdownDrawsExactlyOnePopupPerFrame(): void
    {
        $opened = self::opened(Dropdown::new(Select::new(['json', 'yaml'])));

        self::assertSame(
            1,
            substr_count(self::screen($opened)->getPlainText(), '╭'),
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
            self::screen($dropdown)->getPlainText(),
            'and that is exactly what it draws',
        );
    }

    /**
     * A list floating over the layout belongs to whatever holds the keyboard. Left
     * hanging once the keyboard has gone it covers rows the reader is working in, and
     * nothing they press shuts it.
     */
    #[Test]
    public function anOpenListGoesWhenTheKeyboardMovesToAnotherComponent(): void
    {
        $driven = ModelHarness::for(new PickerBesideAFieldApp(), 30, 12)->initialize();

        $driven->dispatch(new KeyMsg(' '));
        self::assertStringContainsString(
            'yaml',
            $driven->renderScreen()->getPlainText(),
            'precondition: the list is open',
        );

        $driven->dispatch(new KeyMsg(Key::Tab));

        self::assertStringNotContainsString('yaml', $driven->renderScreen()->getPlainText());
    }

    /**
     * The anchor row (row 0) always redraws the current selection, open or
     * closed, so it would make a scroll-into-view assertion pass regardless
     * of what the popup itself shows. Only rows below it prove the popup drew.
     */
    private static function popupBody(Frame $frame): string
    {
        $screen = ScreenBuffer::fromFrame($frame);
        $rows = [];

        for ($row = 1; $row < $frame->height; ++$row) {
            $rows[] = $screen->getLine($row);
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

    private static function frameFor(Dropdown $dropdown, Theme $theme): Frame
    {
        $frame = new Frame(30, 6);
        (new RenderContext($theme))->renderRoot($dropdown, $frame, self::anchor());

        return $frame;
    }

    /**
     * The marker occupies cell 0 of row 0, and carries the row's style.
     */
    private static function foregroundOfFirstCell(Frame $frame): int
    {
        return $frame->foreground(0);
    }

    private static function screen(Dropdown $dropdown): ScreenBuffer
    {
        $frame = new Frame(30, 12);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($dropdown, $frame, self::anchor());

        return ScreenBuffer::fromFrame($frame);
    }

    private static function opened(Dropdown $dropdown): Dropdown
    {
        return $dropdown->withFocus(true)->update(new KeyMsg(' '), self::anchor())->model;
    }
}

final readonly class PickerBesideAFieldApp extends App
{
    public function __construct(
        public ?Dropdown $picker = null,
        public TextInput $field = new TextInput(),
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
