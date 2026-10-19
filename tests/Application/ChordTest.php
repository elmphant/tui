<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChordTest extends TestCase
{
    #[Test]
    public function aTwoKeyChordMovesAListToItsFirstRow(): void
    {
        $harness = ModelHarness::for(new ChordListApp(), 30, 8)->initialize()->press('down', 'down', 'g', 'g');

        self::assertSame(0, $harness->model()->list->cursor());
    }

    #[Test]
    public function theKeysOfAChordAreTypedIntoAFocusedField(): void
    {
        $harness = ModelHarness::for(new ChordFieldApp(), 30, 8)->initialize()->type('gg');

        self::assertSame('gg', $harness->model()->field->value());
    }

    #[Test]
    public function escapeCancelsAChordSoItsFirstKeyIsNeverHandled(): void
    {
        $list = ListView::fromStrings(['one', 'two'])->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['g']]));

        $harness = ModelHarness::for(new ChordListApp($list), 30, 8)->initialize()->press('g', 'esc');

        self::assertSame(0, $harness->model()->list->cursor());
    }

    #[Test]
    public function aKeyThatDoesNotGoOnWithTheChordComesAfterTheHeldKey(): void
    {
        $list = ListView::fromStrings(['one', 'two', 'three'])->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['g']]));

        $harness = ModelHarness::for(new ChordListApp($list), 30, 8)->initialize()->press('g', 'up');

        self::assertSame(0, $harness->model()->list->cursor());
    }
}

final readonly class ChordFieldApp extends CopyableTestApp
{
    public TextInput $field;

    public function __construct(?TextInput $field = null)
    {
        $this->field = $field ?? TextInput::new();
    }

    public function view(): Widget
    {
        return Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field));
    }

    protected function globalKeybindings(): Keybindings
    {
        return new Keybindings([Actions::HOME => ['g g']]);
    }
}
