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
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ComponentTreeTest extends TestCase
{
    #[Test]
    public function registryDiscoversNestedComponentNodesInVisualOrder(): void
    {
        $app = new TreeComponentApp(TextInput::new());
        $probe = Component::bind(
            ComponentId::named('probe'),
            new ComponentRenderProbe(),
            static fn (App $current, ComponentRenderProbe $probe): App => $current,
        );
        $input = $app->inputNode();
        $root = Layout::column($probe, Layout::row($input));

        $registry = ComponentRegistry::fromWidget($root);

        self::assertSame([$probe, $input], $registry->all());
        self::assertSame([$input], $registry->focusables());
    }

    #[Test]
    public function duplicateComponentIdsInTheWidgetTreeAreRejected(): void
    {
        $app = new TreeComponentApp(TextInput::new());
        $input = $app->inputNode();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate component ID "input"');

        ComponentRegistry::fromWidget(Layout::column($input, $input));
    }

    #[Test]
    public function appRoutesMessagesFromComponentNodesWithoutASecondRegistrationList(): void
    {
        $app = new TreeComponentApp(TextInput::new());

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(20, 1), FocusState::on(ComponentId::named('input')));

        self::assertInstanceOf(TreeComponentApp::class, $result->model);
        self::assertSame('x', $result->model->input->value());
    }

    #[Test]
    public function componentNodeDelegatesRenderingToItsWidget(): void
    {
        $probe = new ComponentRenderProbe();
        $component = Component::bind(
            ComponentId::named('probe'),
            $probe,
            static fn (App $app, ComponentRenderProbe $probe): App => $app,
        );
        $frame = new Frame(20, 5);
        $area = new Rect(2, 1, 10, 3);
        $context = RenderContext::unfocused(Theme::dark());

        $component->render($frame, $area, $context);

        self::assertSame($area, $probe->renderedArea);
    }

    #[Test]
    public function registryIsCachedByImmutableOwnerIdentity(): void
    {
        $owner = new stdClass();
        $root = (new TreeComponentApp(TextInput::new()))->inputNode();
        $viewCalls = 0;
        $view = static function () use (&$viewCalls, $root): Widget {
            ++$viewCalls;

            return $root;
        };

        $first = ComponentRegistry::for($owner, $view);
        $second = ComponentRegistry::for($owner, $view);

        self::assertSame($first, $second);
        self::assertSame(1, $viewCalls);
    }

    #[Test]
    public function registryKeepsEveryComponentOfAMultiChildContainer(): void
    {
        $spinner = Component::bind(
            ComponentId::named('spinner'),
            TextInput::new(),
            static fn (App $current, TextInput $field): App => $current,
        );
        $status = Component::bind(
            ComponentId::named('status'),
            TextInput::new(),
            static fn (App $current, TextInput $field): App => $current,
        );
        $input = (new TreeComponentApp(TextInput::new()))->inputNode();

        $registry = ComponentRegistry::fromWidget(Layout::column($spinner, $status, $input));

        self::assertSame([$spinner, $status, $input], $registry->all());
    }

    #[Test]
    public function anOpenModalTakesTheWholeInputLayer(): void
    {
        $behind = Component::bind(
            ComponentId::named('behind'),
            TextInput::new(),
            static fn (App $current, TextInput $field): App => $current,
        );
        $inside = Component::bind(
            ComponentId::named('inside'),
            TextInput::new(),
            static fn (App $current, TextInput $field): App => $current,
        );

        $modal = Component::bind(
            ComponentId::named('modal'),
            Modal::new('Support', $inside),
            static fn (App $current, Modal $modal): App => $current,
        );

        $registry = ComponentRegistry::fromWidget(Overlay::new($behind, $modal));

        self::assertNotContains($behind, $registry->all(), 'nothing behind the modal stays reachable');
        self::assertContains($inside, $registry->all());
    }

    #[Test]
    public function aModalAroundBoundComponentsMustBeBoundItself(): void
    {
        $inside = Component::bind(
            ComponentId::named('inside'),
            TextInput::new(),
            static fn (App $current, TextInput $field): App => $current,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Component::bind()');

        ComponentRegistry::fromWidget(Overlay::new(TextInput::new(), Modal::new('Support', $inside)));
    }

    #[Test]
    public function registryDiscoversComponentsWithoutResolvingLayout(): void
    {
        $component = (new TreeComponentApp(TextInput::new()))->inputNode();
        $root = new StructuralOnlyContainer($component);

        $registry = ComponentRegistry::fromWidget($root);

        self::assertSame([$component], $registry->all());
    }
}

final class ComponentRenderProbe implements Interactive
{
    public ?Rect $renderedArea = null;

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->renderedArea = $area;
    }
}

final readonly class StructuralOnlyContainer implements Widget, WidgetContainer
{
    public function __construct(private Widget $child)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [$this->child];
    }

    public function placedChildren(Rect $area): iterable
    {
        throw new LogicException('Component discovery must not resolve child rectangles');
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class TreeComponentApp extends App
{
    public function __construct(public TextInput $input)
    {
    }

    /**
     * @return Component<TextInput>
     */
    public function inputNode(): Component
    {
        return Component::bind(
            ComponentId::named('input'),
            $this->input,
            static fn (self $app, TextInput $input): self => new self($input),
        );
    }

    public function view(): Widget
    {
        return $this->inputNode();
    }
}
