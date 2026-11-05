<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TheFooterNamesTheKeyHelpTest extends TestCase
{
    #[Test]
    public function aFieldHoldingTheKeyboardNamesCtrlK(): void
    {
        $harness = ModelHarness::for(FieldAndListApp::start(), 60, 6)->init();

        self::assertStringEndsWith('Ctrl+K  keys', rtrim($harness->screen()->lineAt(5)));
    }

    #[Test]
    public function aListHoldingTheKeyboardNamesTheQuestionMark(): void
    {
        $harness = ModelHarness::for(FieldAndListApp::start(), 60, 6)->init();

        $harness->press('tab');

        self::assertStringEndsWith('?  keys', rtrim($harness->screen()->lineAt(5)));
    }

    #[Test]
    public function aNarrowFooterCutsTheOtherHintsAndKeepsIt(): void
    {
        $harness = ModelHarness::for(FieldAndListApp::start(), 30, 6)->init();

        $harness->press('tab');

        self::assertSame(' Ctrl+Q  quit  …   ?  keys', rtrim($harness->screen()->lineAt(5)));
    }

    #[Test]
    public function aDigitActionIsNamedByItsDigit(): void
    {
        $harness = ModelHarness::for(DigitActionApp::start(), 60, 6)->init();

        $harness->press('tab');

        self::assertStringContainsString(' 1  errors ', $harness->screen()->lineAt(5));
        self::assertSame('errors', $harness->focusedHints()['1'] ?? null);
    }

    #[Test]
    public function aComponentActionNamesItsKeyOverTheWidgetsOwnMeaning(): void
    {
        $harness = ModelHarness::for(new EscapingFilterApp(FilterableList::new([new SimpleListItem('alpha'), new SimpleListItem('beta')])), 60, 8)->init();

        $harness->press('/')->type('al')->press('enter');

        self::assertSame('close detail', $harness->focusedHints()['Esc'] ?? null);
    }

    #[Test]
    public function anAppWithoutKeyHelpNamesNone(): void
    {
        $harness = ModelHarness::for(new WithoutKeyHelpApp(TextInput::new()), 60, 6)->init();

        self::assertStringNotContainsString('keys', $harness->screen()->lineAt(5));
    }
}

final readonly class FieldAndListApp extends App
{
    public function __construct(public TextInput $field, public ListView $list)
    {
    }

    public static function start(): self
    {
        return new self(TextInput::new(), ListView::new([new SimpleListItem('one'), new SimpleListItem('two')]));
    }

    public function view(): Widget
    {
        return Layout::column($this->bind('field'), $this->bind('list'), Footer::new()->withLeft(FocusHints::new()));
    }
}

final readonly class DigitActionApp extends App
{
    public function __construct(public TextInput $field, public ListView $list)
    {
    }

    public static function start(): self
    {
        return new self(TextInput::new(), ListView::new([new SimpleListItem('one')]));
    }

    public function view(): Widget
    {
        return Layout::column($this->bind('field'), $this->bind('list'), Footer::new()->withLeft(FocusHints::new()));
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('errors', ['1'], static fn (self $app): self => $app, 'errors');
    }
}

final readonly class EscapingFilterApp extends App
{
    public function __construct(public FilterableList $list)
    {
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('list')->withAction('close', ['esc'], static fn (self $app): self => $app, 'close detail'),
            Footer::new()->withLeft(FocusHints::new()),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return new Keybindings([Actions::SEARCH => ['/']]);
    }
}

final readonly class WithoutKeyHelpApp extends App
{
    public function __construct(public TextInput $field)
    {
    }

    public function view(): Widget
    {
        return Layout::column($this->bind('field'), Footer::new()->withLeft(FocusHints::new()));
    }

    protected function keyHelpKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }
}
