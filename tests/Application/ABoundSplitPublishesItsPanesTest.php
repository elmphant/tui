<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ABoundSplitPublishesItsPanesTest extends TestCase
{
    #[Test]
    public function tabWalksThePanesAndTypingReachesThePaneThatHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(new SplitOfFieldsApp(Split::row(SplitState::even(2), TextInput::new(), TextInput::new())), 60, 10)->init();

        $harness->type('a')->press('tab')->type('b');

        self::assertSame(['a', 'b'], $harness->model()->values());
    }

    #[Test]
    public function aPaneHearsAKeyOnce(): void
    {
        $harness = ModelHarness::for(new SplitOfFieldsApp(Split::row(SplitState::even(2), TextInput::new(), TextInput::new())), 60, 10)->init();

        $harness->type('a');

        self::assertSame(['a', ''], $harness->model()->values());
    }

    #[Test]
    public function aClickReachesTheListInThePaneUnderIt(): void
    {
        $harness = ModelHarness::for(new SplitOfFieldsApp(self::listsSideBySide()), 60, 10)->init();

        $harness->click('right c');

        self::assertSame([0, 2], $harness->model()->cursors());
    }

    #[Test]
    public function theWheelReachesThePaneUnderIt(): void
    {
        $long = ListView::new(array_map(static fn (int $row): SimpleListItem => new SimpleListItem("row {$row}"), range(1, 30)));
        $harness = ModelHarness::for(new SplitOfFieldsApp(Split::row(SplitState::even(2), $long, Text::new('right'))), 60, 6)->init();

        $harness->wheel('row 1', 3);

        self::assertNotSame($long, $harness->model()->split->children()[0]);
    }

    #[Test]
    public function aPaneAnswersTheKeysTheAppRebinds(): void
    {
        $harness = ModelHarness::for(new SplitOfFieldsApp(self::listsSideBySide(), vim: true), 60, 10)->init();

        $harness->press('j');

        self::assertSame([1, 0], $harness->model()->cursors());
    }

    private static function listsSideBySide(): Split
    {
        $list = static fn (string $side): ListView => ListView::new(array_map(static fn (string $label): SimpleListItem => new SimpleListItem("{$side} {$label}"), ['a', 'b', 'c']));

        return Split::row(SplitState::even(2), $list('left'), $list('right'));
    }
}

final readonly class SplitOfFieldsApp extends CopyableTestApp
{
    public function __construct(public Split $split, public bool $vim = false)
    {
    }

    public function view(): Widget
    {
        return Component::bind('split', $this->split, static fn (self $app, Split $split): self => new self($split, $app->vim));
    }

    /**
     * @return list<int>
     */
    public function cursors(): array
    {
        $cursors = [];
        foreach ($this->split->children() as $child) {
            $cursors[] = $child instanceof ListView ? $child->cursor() : -1;
        }

        return $cursors;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        $values = [];
        foreach ($this->split->children() as $child) {
            $values[] = $child instanceof TextInput ? $child->value() : '';
        }

        return $values;
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->vim ? VimMode::keybindings() : Keybindings::empty();
    }
}
