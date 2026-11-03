<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\ComponentRegistry;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentTest extends TestCase
{
    #[Test]
    public function bindAcceptsAReadableStringId(): void
    {
        $component = Component::bind(
            'search',
            TextInput::new(),
            static fn (ComponentContractApp $app, TextInput $field): ComponentContractApp => $app,
        );

        self::assertSame('search', $component->id->value);
    }

    #[Test]
    public function aReplacementThatReturnsADifferentAppIsRejected(): void
    {
        $app = new ComponentContractApp(TextInput::new());
        $component = Component::bind(
            ComponentId::named('input'),
            TextInput::new(),
            static fn (App $current, TextInput $input): App => new DisappearingComponentApp($input),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component "input" replacement must return');

        $component->replaceIn($app, TextInput::new(), new KeyMsg('x'));
    }

    #[Test]
    public function bindRejectsAnEmptyStringId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Component ID must not be empty');

        Component::bind(
            '',
            TextInput::new(),
            static fn (ComponentContractApp $app, TextInput $field): ComponentContractApp => $app,
        );
    }

    #[Test]
    public function componentIdsAreNonEmptyValues(): void
    {
        self::assertTrue(ComponentId::named('search')->equals(ComponentId::named('search')));
        self::assertFalse(ComponentId::named('search')->equals(ComponentId::named('results')));

        $this->expectException(InvalidArgumentException::class);
        ComponentId::named('');
    }

    #[Test]
    public function registryRejectsInvalidAndDuplicateBindings(): void
    {
        $app = new ComponentContractApp(TextInput::new());
        $binding = $app->binding();

        try {
            ComponentRegistry::from([$binding, $binding]);
            self::fail('Duplicate IDs must be rejected');
        } catch (LogicException $error) {
            self::assertStringContainsString('Duplicate component ID', $error->getMessage());
        }

        $this->expectException(LogicException::class);
        ComponentRegistry::from(['not a component']);
    }

    #[Test]
    public function registryFocusablesFiltersNonFocusableComponentsAndReindexesTheList(): void
    {
        $app = new ComponentContractApp(TextInput::new());
        $passive = Component::bind(
            ComponentId::named('passive'),
            new PassiveInteractive(),
            static fn (App $current, PassiveInteractive $widget): App => $current,
        );
        $input = $app->binding();

        $focusables = ComponentRegistry::from([$passive, $input])->focusables();

        self::assertSame([$input], $focusables);
        self::assertSame([0], array_keys($focusables));
    }

    #[Test]
    public function replacementReceivesTheCurrentAppInsteadOfACapturedOwner(): void
    {
        $original = new ComponentContractApp(TextInput::new(), version: 1);
        $current = new ComponentContractApp(TextInput::new(), version: 2);
        $updatedInput = TextInput::new()->withValue('updated');

        $updated = $original->binding()->replaceIn($current, $updatedInput, new KeyMsg('x'))->model;

        self::assertInstanceOf(ComponentContractApp::class, $updated);
        self::assertSame(2, $updated->version);
        self::assertSame('updated', $updated->input->value());
    }

    #[Test]
    public function replacementClosureMustBeStatic(): void
    {
        $app = new ComponentContractApp(TextInput::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be static');

        Component::bind(
            ComponentId::named('unsafe'),
            $app->input,
            fn (App $current, TextInput $input): App => $current,
        );
    }

    #[Test]
    public function replacementClosureMustNotCaptureObjectState(): void
    {
        $app = new ComponentContractApp(TextInput::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not capture object state');

        Component::bind(
            ComponentId::named('stale-owner'),
            $app->input,
            static fn (App $current, TextInput $input): App => $app,
        );
    }

    #[Test]
    public function replacementClosureMayCaptureEnums(): void
    {
        $app = new ComponentContractApp(TextInput::new());
        $direction = Direction::Vertical;

        $component = Component::bind(
            ComponentId::named('enum-configured'),
            $app->input,
            static fn (App $current, TextInput $input): App => new ComponentContractApp($input, $direction === Direction::Vertical ? 1 : 2),
        );

        self::assertSame('enum-configured', $component->id->value);
    }

    #[Test]
    public function replacementClosureMayCaptureScalarConfiguration(): void
    {
        $version = 42;
        $app = new ComponentContractApp(TextInput::new());
        $component = Component::bind(
            ComponentId::named('configured'),
            $app->input,
            static fn (App $current, TextInput $input): App => new ComponentContractApp($input, $version),
        );

        $updated = $component->replaceIn($app, TextInput::new()->withValue('safe'), new KeyMsg('x'))->model;

        self::assertInstanceOf(ComponentContractApp::class, $updated);
        self::assertSame(42, $updated->version);
        self::assertSame('safe', $updated->input->value());
    }

    #[Test]
    public function bindingAPropertyWritesTheUpdateBackToIt(): void
    {
        $app = new PropertyBoundApp(TextInput::new(), version: 7);

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(20, 1), FocusState::on(ComponentId::named('input')));

        self::assertInstanceOf(PropertyBoundApp::class, $result->model);
        self::assertSame('x', $result->model->input->value());
        self::assertSame(7, $result->model->version);
    }

    #[Test]
    public function bindPropertyTakesAnIdOfItsOwnOverThePropertyName(): void
    {
        $ids = array_map(
            static fn (ComponentId $id): string => $id->value,
            (new RenamedPropertyApp(TextInput::new()))->focusableIds(),
        );

        self::assertSame(['search-field'], $ids);
    }

    #[Test]
    public function aWriteBackMayCloseTheComponentItStores(): void
    {
        $app = new DisappearingComponentApp(TextInput::new());

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(20, 1), FocusState::on(ComponentId::named('input')));

        self::assertInstanceOf(DisappearingComponentApp::class, $result->model);
        self::assertSame('x', $result->model->input->value());
        self::assertSame([], $result->model->focusableIds());
    }

    #[Test]
    public function aReplacementWritingToTheWrongPropertyIsRefused(): void
    {
        $app = new MisdirectedComponentApp(TextInput::new(), TextInput::new());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component "typed" stored its update on another property');

        $app->update(new KeyMsg('x'), Rect::fromSize(20, 1), FocusState::on(ComponentId::named('typed')));
    }

    #[Test]
    public function aReplacementThatGivesTheModelBackUnchangedIsRefused(): void
    {
        $app = new DroppingComponentApp();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component "typed" had nowhere to store its update: its replacement gave the model back unchanged');

        $app->update(new KeyMsg('x'), Rect::fromSize(20, 1), FocusState::on(ComponentId::named('typed')));
    }
}

final readonly class PassiveInteractive implements Interactive
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class DroppingComponentApp extends App
{
    public function view(): Widget
    {
        return Component::bind(ComponentId::named('typed'), TextInput::new(), static fn (self $app, TextInput $input): self => $app);
    }
}

final readonly class ComponentContractApp extends App
{
    public function __construct(
        public TextInput $input,
        public int $version = 0,
    ) {
    }

    public function binding(): Component
    {
        return Component::bind(
            ComponentId::named('input'),
            $this->input,
            static fn (self $app, TextInput $input): self => new self($input, $app->version),
        );
    }

    public function view(): Widget
    {
        return $this->binding();
    }
}

final readonly class PropertyBoundApp extends App
{
    public function __construct(
        public TextInput $input,
        public int $version = 0,
    ) {
    }

    public function view(): Widget
    {
        return $this->bindProperty('input', $this->input);
    }
}

final readonly class RenamedPropertyApp extends App
{
    public function __construct(public TextInput $input)
    {
    }

    public function view(): Widget
    {
        return $this->bindProperty('input', $this->input, 'search-field');
    }
}

final readonly class MisdirectedComponentApp extends App
{
    public function __construct(
        public TextInput $typed,
        public TextInput $other,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('typed'),
            $this->typed,
            static fn (self $app, TextInput $input): self => new self($app->typed, $input),
        );
    }
}

final readonly class DisappearingComponentApp extends App
{
    public function __construct(
        public TextInput $input,
        private bool $registered = true,
    ) {
    }

    public function view(): Widget
    {
        if (!$this->registered) {
            return $this->input;
        }

        return Component::bind(
            ComponentId::named('input'),
            $this->input,
            static fn (App $app, TextInput $input): self => new self($input, false),
        );
    }
}
