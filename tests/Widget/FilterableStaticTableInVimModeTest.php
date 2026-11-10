<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableStaticTableInVimModeTest extends TestCase
{
    #[Test]
    public function theKeyHelpListsEnterOnlyWhileItHandsTheKeysBack(): void
    {
        $harness = self::harness();

        self::assertArrayNotHasKey('Enter', self::keysHere($harness), 'Enter does nothing on the table');

        $harness->press('/');

        self::assertSame('back', self::keysHere($harness)['Esc/Enter'] ?? null, 'as the footer says');
        self::assertSame(['Ctrl+Q' => 'quit', 'Esc/Enter' => 'back'], $harness->focusedHints());
    }

    #[Test]
    public function theKeyHelpListsAGlobalKeyOnlyWhereSomethingOnScreenAnswersIt(): void
    {
        $harness = self::harness();
        $app = $harness->model();
        $help = array_map(
            static fn (array $entries): array => array_column($entries, 'description', 'label'),
            $app->keyHelp(self::stateOf($harness)),
        );

        self::assertSame('search', $help['Here']['/'] ?? null, 'the table answers / itself');
        self::assertSame('scroll', $help['Around it']['j/k/↑/↓'] ?? null, 'the pane around it scrolls on j');
        self::assertSame([], $help['In every pane'] ?? [], 'nothing here moves left or right, or switches tabs');
    }

    #[Test]
    public function jScrollsThePaneAroundTheTableAndTypesNothing(): void
    {
        $harness = self::harness()->press('j', 'j');

        self::assertSame(2, $harness->model()->scrollOffset());
        self::assertSame('', $harness->model()->table()->query());
    }

    #[Test]
    public function capitalGAndCtrlUMoveThePaneAroundTheTable(): void
    {
        $harness = self::harness()->press('G');
        $bottom = $harness->model()->scrollOffset();

        self::assertGreaterThan(0, $bottom);

        $harness->press('ctrl+u');

        self::assertLessThan($bottom, $harness->model()->scrollOffset());
    }

    #[Test]
    public function slashHandsTheKeysToTheFieldAndEscBackKeepingTheFilter(): void
    {
        $harness = self::harness()->press('/')->type('route 1j');

        self::assertSame('route 1j', $harness->model()->table()->query());

        $harness->press('backspace', 'esc', 'j');

        self::assertSame('route 1', $harness->model()->table()->query());
        self::assertSame(1, $harness->model()->scrollOffset());
    }

    #[Test]
    public function escOnTheTableClearsTheFilter(): void
    {
        $harness = self::harness()->press('/')->type('route 1')->press('enter', 'esc');

        self::assertSame('', $harness->model()->table()->query());
    }

    #[Test]
    public function theHintsSayWhichHalfHoldsTheKeys(): void
    {
        $harness = self::harness();

        self::assertSame(['Ctrl+Q' => 'quit', '/' => 'search'], $harness->focusedHints());
        self::assertSame(['Ctrl+Q' => 'quit', 'Esc/Enter' => 'back'], $harness->press('/')->focusedHints());
        self::assertSame(['Ctrl+Q' => 'quit', 'Esc' => 'clear filter', '/' => 'search'], $harness->type('r')->press('enter')->focusedHints());
    }

    #[Test]
    public function theCaretShowsOnlyWhileTheFieldHoldsTheKeys(): void
    {
        $harness = self::harness();

        self::assertNull($harness->renderFrame()->cursorPosition());
        self::assertNotNull($harness->press('/')->renderFrame()->cursorPosition());
        self::assertNull($harness->press('enter')->renderFrame()->cursorPosition());
    }

    #[Test]
    public function withoutVimModeLettersFilterStraightAway(): void
    {
        $harness = self::harness(vim: false)->type('route 1j');

        self::assertSame('route 1j', $harness->model()->table()->query());
        self::assertNotNull($harness->renderFrame()->cursorPosition());
    }

    /**
     * @return array<array-key, string>
     */
    private static function keysHere(ModelHarness $harness): array
    {
        $app = $harness->model();
        self::assertInstanceOf(VimOutputApp::class, $app);

        return array_column($app->keyHelp(self::stateOf($harness))['Here'] ?? [], 'description', 'label');
    }

    private static function stateOf(ModelHarness $harness): FocusState
    {
        $focused = $harness->focused();
        self::assertNotNull($focused, 'precondition: something holds the keyboard');

        return FocusState::on($focused);
    }

    private static function harness(bool $vim = true): ModelHarness
    {
        return ModelHarness::for(new VimOutputApp(self::outputPane(), $vim), 40, 10)->init();
    }

    private static function outputPane(): Panel
    {
        return Panel::new('Output')->add(
            FilterableStaticTable::new(['Route'], array_map(static fn (int $index): array => ["route $index"], range(1, 40))),
        );
    }
}

final readonly class VimOutputApp extends CopyableTestApp
{
    public function __construct(public Panel $panel, private bool $vim)
    {
    }

    public function scrollOffset(): int
    {
        return $this->panel->scrollOffset(new Rect(0, 0, 40, 10));
    }

    public function table(): FilterableStaticTable
    {
        $table = iterator_to_array($this->panel->children())[0];
        TestCase::assertInstanceOf(FilterableStaticTable::class, $table);

        return $table;
    }

    public function view(): Widget
    {
        return Component::bind('output', $this->panel, static fn (self $app, Panel $panel): self => new self($panel, $app->vim));
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->vim ? VimMode::keybindings() : Keybindings::empty();
    }
}
