<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Runtime\BindsChords;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountedChordsTest extends TestCase
{
    #[Test]
    public function aMountedModelIsAskedForItsChordsWithTheKeyboardNamedInItsOwnWords(): void
    {
        $host = new ChordHost(new ChordedPair());

        self::assertContains('top', $host->chords(new FocusState(ComponentId::named('pair/second'), decided: true))->actions());
        self::assertNotContains('top', $host->chords(new FocusState(ComponentId::named('pair/first'), decided: true))->actions());
    }

    #[Test]
    public function aMountedModelIsToldTheKeyboardIsNowhereInItWhenItIsElsewhere(): void
    {
        $host = new ChordHost(new ChordedPair());

        self::assertContains('nowhere', $host->chords(FocusState::nowhere())->actions());
    }
}

final readonly class ChordedPair implements Reducer, BindsChords
{
    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', TextInput::new(), static fn (self $pair): self => $pair),
            Component::bind('second', TextInput::new(), static fn (self $pair): self => $pair),
        );
    }

    public function chords(FocusState $focus): Keybindings
    {
        return match (true) {
            $focus->focused()?->value === 'second' => new Keybindings(['top' => ['g g']]),
            $focus->focused() === null && !$focus->isUndecided() => new Keybindings(['nowhere' => ['z z']]),
            default => Keybindings::empty(),
        };
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }
}

final readonly class ChordHost extends App
{
    public function __construct(public ChordedPair $chorded)
    {
    }

    public function view(): Widget
    {
        return Mount::named('pair', $this->chorded, $this->chorded->view(), static fn (self $host): self => $host);
    }
}
