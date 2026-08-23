<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\ComponentRegistry;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Feedback\Spinner;
use Elmphant\Tui\Widget\TextInput;
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
            Spinner::new(),
            static fn (ComponentContractApp $app, Spinner $spinner): ComponentContractApp => $app,
        );

        self::assertSame('search', $component->id->value);
    }

    #[Test]
    public function aReplacementThatReturnsADifferentAppIsRejected(): void
    {
        $app = new ComponentContractApp();
        $component = Component::bind(
            ComponentId::named('input'),
            new TextInput(),
            static fn (App $current, TextInput $input): App => new DisappearingComponentApp($input),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component "input" replacement must return');

        $component->replaceIn($app, new TextInput());
    }

    #[Test]
    public function bindRejectsAnEmptyStringId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Component ID must not be empty');

        Component::bind(
            '',
            Spinner::new(),
            static fn (ComponentContractApp $app, Spinner $spinner): ComponentContractApp => $app,
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
        $app = new ComponentContractApp();
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
        $app = new ComponentContractApp();
        $spinner = Component::bind(
            ComponentId::named('spinner'),
            Spinner::new(),
            static fn (App $current, Spinner $spinner): App => $current,
        );
        $input = $app->binding();

        $focusables = ComponentRegistry::from([$spinner, $input])->focusables();

        self::assertSame([$input], $focusables);
        self::assertSame([0], array_keys($focusables));
    }

    #[Test]
    public function replacementReceivesTheCurrentAppInsteadOfACapturedOwner(): void
    {
        $original = new ComponentContractApp(version: 1);
        $current = new ComponentContractApp(version: 2);
        $updatedInput = TextInput::new()->withValue('updated');

        $updated = $original->binding()->replaceIn($current, $updatedInput);

        self::assertInstanceOf(ComponentContractApp::class, $updated);
        self::assertSame(2, $updated->version);
        self::assertSame('updated', $updated->input->value());
    }

    #[Test]
    public function replacementClosureMustBeStatic(): void
    {
        $app = new ComponentContractApp();

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
        $app = new ComponentContractApp();

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
        $app = new ComponentContractApp();
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
        $app = new ComponentContractApp();
        $component = Component::bind(
            ComponentId::named('configured'),
            $app->input,
            static fn (App $current, TextInput $input): App => new ComponentContractApp($input, $version),
        );

        $updated = $component->replaceIn($app, TextInput::new()->withValue('safe'));

        self::assertInstanceOf(ComponentContractApp::class, $updated);
        self::assertSame(42, $updated->version);
        self::assertSame('safe', $updated->input->value());
    }

    #[Test]
    public function bindingAPropertyWritesTheUpdateBackToIt(): void
    {
        $app = new PropertyBoundApp(TextInput::new()->withFocus(true), version: 7);

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(20, 1));

        self::assertInstanceOf(PropertyBoundApp::class, $result->model);
        self::assertSame('x', $result->model->input->value());
        self::assertSame(7, $result->model->version);
    }

    #[Test]
    public function aComponentCannotDisappearWhileItsUpdateIsBeingApplied(): void
    {
        $app = new DisappearingComponentApp(TextInput::new()->withFocus(true));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component "input" disappeared during an update');

        $app->update(new KeyMsg('x'), Rect::fromSize(20, 1));
    }

    #[Test]
    public function aReplacementWritingToTheWrongPropertyIsRefused(): void
    {
        $app = new MisdirectedComponentApp(TextInput::new()->withFocus(true));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component "typed" stored its update on another property');

        $app->update(new KeyMsg('x'), Rect::fromSize(20, 1));
    }
}

final readonly class ComponentContractApp extends App
{
    public function __construct(
        public TextInput $input = new TextInput(),
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
        public TextInput $input = new TextInput(),
        public int $version = 0,
    ) {
    }

    public function view(): Widget
    {
        return $this->bindProperty('input', $this->input);
    }
}

final readonly class MisdirectedComponentApp extends App
{
    public function __construct(
        public TextInput $typed = new TextInput(),
        public TextInput $other = new TextInput(),
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
