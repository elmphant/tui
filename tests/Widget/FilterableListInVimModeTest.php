<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class FilterableListInVimModeTest extends TestCase
{
    #[Test]
    public function jMovesTheListAndTypesNothing(): void
    {
        $harness = self::harness()->press('j');

        self::assertSame(1, $harness->model()->list->sourceIndex());
        self::assertSame('', $harness->model()->list->query());
    }

    #[Test]
    public function slashHandsTheKeysToTheFieldSoLettersFilter(): void
    {
        $harness = self::harness()->press('/')->type('tj');

        self::assertSame('tj', $harness->model()->list->query());
        self::assertTrue($harness->model()->list->withGlobalKeybindings(VimMode::keybindings())->takesText());
    }

    #[Test]
    public function escHandsTheKeysBackToTheListAndKeepsTheFilter(): void
    {
        $harness = self::harness()->press('/')->type('t')->press('esc', 'j');

        self::assertSame('t', $harness->model()->list->query());
        self::assertSame(2, $harness->model()->list->sourceIndex());
    }

    #[Test]
    public function enterHandsTheKeysBackWithoutChoosing(): void
    {
        $harness = self::harness()->press('/')->type('t')->press('enter');

        self::assertNull($harness->model()->list->selectedItem());
        self::assertFalse($harness->model()->list->withGlobalKeybindings(VimMode::keybindings())->takesText());

        $harness->press('enter');

        self::assertSame('two', $harness->model()->list->selectedItem()?->title());
    }

    #[Test]
    public function escOnTheListClearsTheFilter(): void
    {
        $harness = self::harness()->press('/')->type('t')->press('esc', 'esc');

        self::assertSame('', $harness->model()->list->query());
    }

    #[Test]
    public function capitalGAndGgJumpTheListToItsEnds(): void
    {
        $harness = self::harness()->press('G');

        self::assertSame(3, $harness->model()->list->sourceIndex());

        $harness->press('g', 'g');

        self::assertSame(0, $harness->model()->list->sourceIndex());
    }

    #[Test]
    public function theListOffersSlashToSearch(): void
    {
        self::assertSame(['j/k' => 'move', 'Enter' => 'choose', '/' => 'search'], self::harness()->focusedHints());
    }

    #[Test]
    public function theFieldOffersEscAndEnterBack(): void
    {
        self::assertSame(['Esc/Enter' => 'back'], self::harness()->press('/')->focusedHints());
    }

    #[Test]
    public function withoutVimModeLettersFilterStraightAway(): void
    {
        $harness = ModelHarness::for(new VimListApp(self::list(), vim: false), 40, 8)->initialize()->type('tj');

        self::assertSame('tj', $harness->model()->list->query());
    }

    #[Test]
    public function theCaretShowsOnlyWhileTheFieldHoldsTheKeys(): void
    {
        $harness = self::harness();

        self::assertNull($harness->renderFrame()->cursorPosition());
        self::assertNotNull($harness->press('/')->renderFrame()->cursorPosition());
        self::assertNull($harness->press('esc')->renderFrame()->cursorPosition());
    }

    #[Test]
    public function enterInTheFieldOfAnOpenDropdownKeepsItOpenAndAPickClosesIt(): void
    {
        $harness = ModelHarness::for(new VimDropdownApp(Dropdown::new(self::list())->withOpen()), 40, 10)
            ->initialize()
            ->press('/')
            ->type('th')
            ->press('enter');

        self::assertTrue($harness->model()->dropdown->isOpen());

        $harness->press('enter');

        self::assertFalse($harness->model()->dropdown->isOpen());
        self::assertSame('three', $harness->model()->dropdown->value());
    }

    #[Test]
    public function aRunningProgramShowsTheCaretAfterSlashAndHidesItAfterEnter(): void
    {
        $terminal = new VirtualTerminal(40, 8);
        $clock = new TestClock(1000.0);
        $program = Program::new(new VimListApp(self::list()))->withTerminal($terminal)->withClock($clock);
        $visible = [];

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$visible): void {
            foreach (['', '/', "\r"] as $input) {
                $terminal->simulateInput($input);
                $clock->advance(1);
                $program->tick();
                $visible[] = (new ScreenBuffer($terminal->output(), 40, 8))->isCursorVisible();
            }
            $terminal->simulateInput("\x11");
        });
        $program->start();

        self::assertSame([false, true, false], $visible);
    }

    private static function harness(): ModelHarness
    {
        return ModelHarness::for(new VimListApp(self::list()), 40, 8)->initialize();
    }

    private static function list(): FilterableList
    {
        return FilterableList::new(array_map(static fn (string $title): SimpleListItem => new SimpleListItem($title), ['one', 'two', 'three', 'four']));
    }
}

final readonly class VimListApp extends CopyableTestApp
{
    public function __construct(public FilterableList $list, private bool $vim = true)
    {
    }

    public function view(): Widget
    {
        return Component::bind('list', $this->list, static fn (self $app, FilterableList $list): self => new self($list, $app->vim));
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->vim ? VimMode::keybindings() : Keybindings::empty();
    }
}

final readonly class VimDropdownApp extends CopyableTestApp
{
    public function __construct(public Dropdown $dropdown)
    {
    }

    public function view(): Widget
    {
        return Component::bind('dropdown', $this->dropdown, static fn (self $app, Dropdown $dropdown): self => new self($dropdown));
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}
