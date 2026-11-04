<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupInVimModeTest extends TestCase
{
    #[Test]
    public function jMovesToTheNextBoxAndTypesNothing(): void
    {
        $harness = self::harness()->press('j', 'space');

        self::assertSame(['beta'], $harness->model()->group->checkedValues());
    }

    #[Test]
    public function slashHandsTheKeysToTheFieldAndEnterBackKeepingTheFilter(): void
    {
        $harness = self::harness()->press('/')->type('gj');

        self::assertNull($harness->screen()->find('alpha'));

        $harness->press('backspace', 'enter', 'space');

        self::assertSame(['gamma'], $harness->model()->group->checkedValues());
        self::assertNull($harness->screen()->find('alpha'));
    }

    #[Test]
    public function theHintsSayWhichHalfHoldsTheKeys(): void
    {
        $harness = self::harness();

        self::assertSame(['j/k' => 'move', 'Enter' => 'toggle', '/' => 'search'], $harness->focusedHints());
        self::assertSame(['Esc/Enter' => 'back'], $harness->press('/')->focusedHints());
    }

    #[Test]
    public function theCaretShowsOnlyWhileTheFieldHoldsTheKeys(): void
    {
        $harness = self::harness();

        self::assertNull($harness->renderFrame()->cursorPosition());
        self::assertNotNull($harness->press('/')->renderFrame()->cursorPosition());
        self::assertNull($harness->press('esc')->renderFrame()->cursorPosition());
    }

    private static function harness(): ModelHarness
    {
        return ModelHarness::for(new VimBoxesApp(FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma'])), 40, 8)->init();
    }
}

final readonly class VimBoxesApp extends CopyableTestApp
{
    public function __construct(public FilterableCheckboxGroup $group)
    {
    }

    public function view(): Widget
    {
        return Component::bind('group', $this->group, static fn (self $app, FilterableCheckboxGroup $group): self => new self($group));
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}
