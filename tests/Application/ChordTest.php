<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\BindsChords;
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
    public function aChordNothingOnScreenAnswersIsNoChordAtAll(): void
    {
        $list = ListView::fromStrings(['one', 'two'])->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['t']]));
        $globals = new Keybindings([Actions::HOME => ['g g'], Actions::TAB_NEXT => ['g t']]);

        $harness = ModelHarness::for(new ChordListApp($list, $globals), 30, 8)->initialize()->press('g', 't');

        self::assertSame(1, $harness->model()->list->cursor());
    }

    #[Test]
    public function aMountedModelHearsAChordOfItsOwnWhileTheKeyboardIsInIt(): void
    {
        $harness = ModelHarness::for(new ChordMountingApp(new ChordMountedLog(ListView::fromStrings(['a', 'b']))), 30, 8)
            ->initialize()
            ->press('d', 'd');

        self::assertSame('log/list', $harness->focused()?->value);
        self::assertTrue($harness->model()->log->cleared);
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
    public function aHeldKeyIsHandedOnAloneOnceHalfASecondPasses(): void
    {
        $list = ListView::fromStrings(['one', 'two', 'three'])->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['g']]));
        $harness = ModelHarness::for(new ChordListApp($list), 30, 8)->initialize()->press('g');

        $waiting = $harness->advance(0.4)->model()->list->cursor();
        $handed = $harness->advance(0.2)->model()->list->cursor();

        self::assertSame([0, 1], [$waiting, $handed]);
    }

    #[Test]
    public function aKeyThatDoesNotGoOnWithTheChordComesAfterTheHeldKey(): void
    {
        $list = ListView::fromStrings(['one', 'two', 'three'])->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['g']]));

        $harness = ModelHarness::for(new ChordListApp($list), 30, 8)->initialize()->press('g', 'up');

        self::assertSame(0, $harness->model()->list->cursor());
    }
}

final readonly class ChordMountingApp extends CopyableTestApp
{
    public function __construct(public ChordMountedLog $log)
    {
    }

    public function view(): Widget
    {
        return Mount::named('log', $this->log, $this->log->view(), static fn (self $app, ChordMountedLog $log): self => new self($log));
    }
}

final readonly class ChordMountedLog implements Reducer, BindsChords
{
    public function __construct(public ListView $list, public bool $cleared = false)
    {
    }

    public function chords(FocusState $focus): Keybindings
    {
        return new Keybindings(['clear' => ['d d']]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof KeyMsg && $this->chords(new FocusState())->matches($message, 'clear')
            ? new UpdateResult(new self($this->list, true))
            : new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Component::bind('list', $this->list, static fn (self $log, ListView $list): self => new self($list, $log->cleared));
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
