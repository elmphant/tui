<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Chrome a reader steps into — a filter row, a toolbar — should not be strung
 * between the panes they read in. Inside it, tab walks its own controls; the way
 * out is a key that says so.
 */
final class FocusScopeRingTest extends TestCase
{
    #[Test]
    public function tabFromTheLastControlComesBackToTheFirstRatherThanLeaving(): void
    {
        self::assertSame('severity', $this->tabbedFrom('search'));
    }

    #[Test]
    public function shiftTabFromTheFirstControlGoesToTheLastOfTheSame(): void
    {
        self::assertSame('search', $this->tabbedFrom('severity', backwards: true));
    }

    #[Test]
    public function theControlsAreNotOnTheWayBetweenThePanes(): void
    {
        $app = new RowAboveThePanesApp(TextInput::new(), TextInput::new(), TextInput::new(), TextInput::new());

        self::assertSame(
            ['timeline', 'entry'],
            array_map(
                static fn (ComponentId $id): string => $id->value,
                $app->focusableIds(new FocusState(ComponentId::named('timeline'))),
            ),
            'a scope is stepped into by a command, so tab never walks it on the way past',
        );
    }

    #[Test]
    public function aScopeBelowThePanesWalksItsOwnControlsToo(): void
    {
        $app = new RowBelowThePanesApp(TextInput::new(), TextInput::new(), TextInput::new(), TextInput::new());
        $focus = new FocusState(ComponentId::named('sources'));

        $command = $app->update(new KeyMsg(Key::Tab), new Rect(0, 0, 40, 10), $focus)->command;

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertSame(
            'severity',
            $command->applyTo($focus, $app->focusableIds($focus))->focused()?->value,
            'a scope is a ring wherever it sits, not only when it comes first',
        );
    }

    /**
     * Taking the scopes off the ring assumes there is content beside the chrome. A
     * tree that is all scope has none, and an empty ring leaves the keyboard nowhere
     * to land.
     */
    #[Test]
    public function aTreeThatIsNothingButAScopeStillOffersItsControls(): void
    {
        self::assertNotSame([], (new NothingButAScopeApp(TextInput::new()))->focusableIds());
    }

    private function tabbedFrom(string $id, bool $backwards = false): ?string
    {
        $app = new RowAboveThePanesApp(TextInput::new(), TextInput::new(), TextInput::new(), TextInput::new());
        $focus = new FocusState(ComponentId::named($id));

        $command = $app->update(
            new KeyMsg(Key::Tab, shift: $backwards),
            new Rect(0, 0, 40, 10),
            $focus,
        )->command;

        self::assertInstanceOf(FocusCmd::class, $command);

        return $command->applyTo($focus, $app->focusableIds($focus))->focused()?->value;
    }
}

final readonly class RowAboveThePanesApp extends App
{
    public function __construct(
        public TextInput $severity,
        public TextInput $search,
        public TextInput $timeline,
        public TextInput $entry,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            new FilterRowScope(Layout::horizontal(
                Component::bind(
                    'severity',
                    $this->severity,
                    static fn (self $app, TextInput $field): self => $app->cloneWith(severity: $field),
                ),
                Component::bind(
                    'search',
                    $this->search,
                    static fn (self $app, TextInput $field): self => $app->cloneWith(search: $field),
                ),
            )),
            Component::bind(
                'timeline',
                $this->timeline,
                static fn (self $app, TextInput $field): self => $app->cloneWith(timeline: $field),
            ),
            Component::bind(
                'entry',
                $this->entry,
                static fn (self $app, TextInput $field): self => $app->cloneWith(entry: $field),
            ),
        );
    }
}

final readonly class RowBelowThePanesApp extends App
{
    public function __construct(
        public TextInput $timeline,
        public TextInput $entry,
        public TextInput $severity,
        public TextInput $sources,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'timeline',
                $this->timeline,
                static fn (self $app, TextInput $field): self => $app->cloneWith(timeline: $field),
            ),
            Component::bind(
                'entry',
                $this->entry,
                static fn (self $app, TextInput $field): self => $app->cloneWith(entry: $field),
            ),
            new FilterRowScope(Layout::horizontal(
                Component::bind(
                    'severity',
                    $this->severity,
                    static fn (self $app, TextInput $field): self => $app->cloneWith(severity: $field),
                ),
                Component::bind(
                    'sources',
                    $this->sources,
                    static fn (self $app, TextInput $field): self => $app->cloneWith(sources: $field),
                ),
            )),
        );
    }
}

final readonly class NothingButAScopeApp extends App
{
    public function __construct(
        public TextInput $only,
    ) {
    }

    public function view(): Widget
    {
        return new FilterRowScope(Component::bind(
            'only',
            $this->only,
            static fn (self $app, TextInput $field): self => $app->cloneWith(only: $field),
        ));
    }
}
