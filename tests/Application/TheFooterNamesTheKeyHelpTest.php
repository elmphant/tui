<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Field\TextInput;
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
