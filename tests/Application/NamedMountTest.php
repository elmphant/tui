<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Initializes;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedMountTest extends TestCase
{
    #[Test]
    public function aComponentInAMountIsKnownUnderTheMountsName(): void
    {
        $harness = ModelHarness::for(new HostOfOneMount(), 40, 6)->initialize();

        self::assertSame('notes/field', $harness->focused()?->value);
    }

    #[Test]
    public function aMountedComponentDrawsAsTheOneHoldingTheKeyboard(): void
    {
        $harness = ModelHarness::for(new HostOfOneMount(new NotesModel(lamp: true)), 40, 6)->initialize();

        self::assertStringContainsString('lit', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function insideAMountTheFocusedComponentGoesByItsLocalName(): void
    {
        $harness = ModelHarness::for(new HostOfOneMount(new NotesModel(namer: true)), 40, 6)->initialize();

        self::assertStringContainsString('focused: field', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function whatAMountedComponentAsksForComesBackToItsModel(): void
    {
        $harness = ModelHarness::for(new HostOfOneMount(new NotesModel(lamp: true)), 40, 6)->initialize()->press('enter');

        self::assertSame(['lamp'], $harness->model()->notes->heard);
    }

    #[Test]
    public function aMountedModelSendsTheKeyboardToItsComponentsByTheirOwnNames(): void
    {
        $harness = ModelHarness::for(new HostOfOneMount(new NotesModel(lamp: true)), 40, 6)->initialize()->press('enter');

        self::assertSame('notes/field', $harness->focused()?->value);
    }

    #[Test]
    public function aKeyReachesTheMountHoldingTheKeyboardBeforeItsComponents(): void
    {
        $harness = ModelHarness::for(new HostOfOneMount(), 40, 6)->initialize()->type('ax');

        self::assertSame(['key:x'], $harness->model()->notes->heard);
        self::assertSame('a', $harness->model()->notes->field?->value());
    }

    #[Test]
    public function withTheKeyboardInNoMountAKeyReachesTheMountsInView(): void
    {
        $harness = ModelHarness::for(new HostOfAQuietMount(), 40, 6)->initialize()->press('r');

        self::assertSame(1, $harness->model()->quiet->refreshed);
    }

    #[Test]
    public function aFocusScopeInAMountKeepsTabToItsOwnControls(): void
    {
        $host = new HostOfAScopedMount();

        self::assertSame(
            ['scoped/a', 'scoped/b'],
            array_map(static fn (ComponentId $id): string => $id->value, $host->focusableIds(new FocusState(ComponentId::named('scoped/a')))),
        );
    }

    #[Test]
    public function aMountBehindAnOpenModalHearsNoKey(): void
    {
        $harness = ModelHarness::for(new HostOfAQuietMount(behindAModal: true), 40, 12)->initialize()->press('r');

        self::assertSame(0, $harness->model()->quiet->refreshed);
    }

    #[Test]
    public function aPressReachesTheMountUnderThePointerWithTheAreaItIsDrawnIn(): void
    {
        $harness = ModelHarness::for(new HostOfAQuietMount(besideASidebar: true), 40, 6)->initialize()->clickAt(15, 2);

        self::assertEquals(new Rect(10, 0, 30, 6), $harness->model()->quiet->pressedIn);
    }

    #[Test]
    public function aMountStartsWithItsInitAndHearsTheAnswerEvenWhileHidden(): void
    {
        $harness = ModelHarness::for(new HostOfAHiddenMount(), 40, 6)->initialize();

        self::assertSame(['started'], $harness->model()->hidden->heard);
    }
}

final readonly class ScopedModel implements Reducer
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function view(): Widget
    {
        $field = static fn (string $id): Component => Component::bind($id, TextInput::new(), static fn (self $model, TextInput $field): self => $model);

        return Layout::vertical(new FilterRowScope(Layout::horizontal($field('a'), $field('b'))), $field('pane'));
    }
}

final readonly class HostOfAScopedMount extends App
{
    public function __construct(public ScopedModel $scoped = new ScopedModel())
    {
    }

    public function view(): Widget
    {
        return Mount::named('scoped', $this->scoped, $this->scoped->view(), static fn (self $host, ScopedModel $scoped): self => $host);
    }
}

final readonly class StartingModel implements Reducer, Initializes
{
    use Cloneable;

    /**
     * @param list<string> $heard
     */
    public function __construct(public array $heard = [])
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::message(new Heard('started'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof Heard
            ? new UpdateResult($this->cloneWith(heard: [...$this->heard, $message->what]))
            : UpdateResult::ignored($this);
    }
}

final readonly class HostOfAHiddenMount extends App
{
    use Cloneable;

    public function __construct(public StartingModel $hidden = new StartingModel())
    {
    }

    public function view(): Widget
    {
        return Text::new('another tab');
    }

    protected function mounts(): array
    {
        return [Mount::named('hidden', $this->hidden, Text::new('hidden'), static fn (self $host, StartingModel $hidden): self => $host->cloneWith(hidden: $hidden))];
    }
}

final readonly class QuietModel implements Reducer
{
    use Cloneable;

    public function __construct(public int $refreshed = 0, public ?Rect $pressedIn = null)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg && $message->key === 'r' => new UpdateResult($this->cloneWith(refreshed: $this->refreshed + 1)),
            $message instanceof MouseMsg && $message->isClick() => new UpdateResult($this->cloneWith(pressedIn: $area)),
            default => UpdateResult::ignored($this),
        };
    }
}

final readonly class HostOfAQuietMount extends App
{
    use Cloneable;

    public function __construct(
        public QuietModel $quiet = new QuietModel(),
        public bool $besideASidebar = false,
        public bool $behindAModal = false,
    ) {
    }

    public function view(): Widget
    {
        $mount = Mount::named('quiet', $this->quiet, Text::new('nothing to focus'), static fn (self $host, QuietModel $quiet): self => $host->cloneWith(quiet: $quiet));

        return match (true) {
            $this->besideASidebar => Layout::horizontal()->add(Text::new('sidebar'), Constraint::exact(10))->add($mount, Constraint::flex()),
            $this->behindAModal => Overlay::new($mount, Modal::new('Notice', Text::new('read me'))),
            default => $mount,
        };
    }
}

final readonly class Heard implements Message
{
    public function __construct(public string $what)
    {
    }
}

final readonly class Lamp implements Focusable
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof KeyMsg && $message->key === Key::Enter
            ? new UpdateResult($this, Cmd::message(new Heard('lamp')))
            : UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, $context->hasFocus() ? 'lit' : 'dark');
    }
}

final readonly class NotesModel implements Reducer
{
    use Cloneable;

    /**
     * @param list<string> $heard
     */
    public function __construct(
        public ?TextInput $field = null,
        public array $heard = [],
        public bool $lamp = false,
        public bool $namer = false,
    ) {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof Heard => new UpdateResult($this->cloneWith(heard: [...$this->heard, $message->what]), Cmd::focus(ComponentId::named('field'))),
            $message instanceof KeyMsg && $message->key === 'x' => new UpdateResult($this->cloneWith(heard: [...$this->heard, 'key:x'])),
            default => UpdateResult::ignored($this),
        };
    }

    public function view(): Widget
    {
        $field = Component::bind(
            'field',
            $this->field ?? TextInput::new(),
            static fn (self $model, TextInput $field): self => $model->cloneWith(field: $field),
        );

        return match (true) {
            $this->lamp => Layout::vertical(Component::bind('lamp', new Lamp(), static fn (self $model, Lamp $lamp): self => $model), $field),
            $this->namer => Layout::vertical($field, new FocusNamer()),
            default => Layout::vertical($field),
        };
    }
}

final readonly class FocusNamer implements Widget
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, 'focused: ' . ($context->focusedComponent()->value ?? 'nothing'));
    }
}

final readonly class HostOfOneMount extends App
{
    use Cloneable;

    public function __construct(public NotesModel $notes = new NotesModel())
    {
    }

    public function view(): Widget
    {
        return Mount::named(
            'notes',
            $this->notes,
            $this->notes->view(),
            static fn (self $host, NotesModel $notes): self => $host->cloneWith(notes: $notes),
        );
    }
}
