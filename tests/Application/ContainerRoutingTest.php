<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use ArrayObject;
use Closure;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Popup\PositionedOverlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Structure\TabPage;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Wrappers must expose rendered children so discovery and input can reach bound components inside them.
 */
final class ContainerRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(Widget): Widget}>
     */
    public static function containerProvider(): iterable
    {
        yield 'nothing' => [static fn (Widget $child): Widget => $child];
        yield 'Container' => [static fn (Widget $child): Widget => Container::around($child)->withPadding(Spacing::all(1))];
        yield 'Box' => [static fn (Widget $child): Widget => Box::new('box')->add($child)];
        yield 'Panel' => [static fn (Widget $child): Widget => Panel::new('panel')->add($child)];
        yield 'Layout' => [static fn (Widget $child): Widget => Layout::column($child)];
        yield 'Flex' => [static fn (Widget $child): Widget => Flex::column()->add($child, Constraint::flex())];
        yield 'Split' => [static fn (Widget $child): Widget => Split::row(new SplitState([500, 500]), $child, Text::new('other'))];
        yield 'TabBar' => [static fn (Widget $child): Widget => TabBar::new()->withTabs([(new TabPage('one'))->add($child)])];
        yield 'Modal' => [static fn (Widget $child): Widget => Component::bind('modal', Modal::new('modal', $child), static fn (App $app, Modal $modal): App => $app)];
        yield 'Overlay' => [static fn (Widget $child): Widget => Overlay::new(Text::new('base'), $child)];
        yield 'PositionedOverlay' => [static fn (Widget $child): Widget => PositionedOverlay::at(0, 0)->withContent($child)];
        yield 'nested' => [static fn (Widget $child): Widget => Container::around(Panel::new('outer')->add(Layout::column($child)))];
    }

    /**
     * @param Closure(Widget): Widget $wrap
     */
    #[DataProvider('containerProvider')]
    #[Test]
    public function aKeyReachesTheComponentWhateverWrapsIt(Closure $wrap): void
    {
        $seen = new ArrayObject();
        $harness = ModelHarness::for(new WrappedPaneApp(new RecordingPane($seen), $wrap), width: 40, height: 12);
        $harness->init();

        $harness->dispatch(new KeyMsg(Key::Down));

        $keys = array_filter(
            iterator_to_array($seen),
            static fn (object $message): bool => $message instanceof KeyMsg,
        );

        self::assertNotSame([], $keys, 'the component never heard the key');
    }

    /**
     * Hit-testing must use the child's drawn position, including any offset added by its wrapper.
     *
     * @param Closure(Widget): Widget $wrap
     */
    #[DataProvider('containerProvider')]
    #[Test]
    public function aClickReachesTheComponentWhateverWrapsIt(Closure $wrap): void
    {
        $seen = new ArrayObject();
        $harness = ModelHarness::for(new WrappedPaneApp(new RecordingPane($seen), $wrap), width: 40, height: 12);
        $harness->init();
        $harness->renderFrame();

        $harness->dispatch(new MouseMsg(20, 6, MouseAction::Press, MouseButton::Left));

        $clicks = array_filter(
            iterator_to_array($seen),
            static fn (object $message): bool => $message instanceof MouseMsg,
        );

        self::assertNotSame([], $clicks, 'the component never heard the click');
    }
}

final readonly class WrappedPaneApp extends App
{
    /**
     * @param Closure(Widget): Widget $wrap
     */
    public function __construct(
        public RecordingPane $pane,
        private Closure $wrap,
    ) {
    }

    public function view(): Widget
    {
        return ($this->wrap)(Component::bind(
            'pane',
            $this->pane,
            static fn (self $app, RecordingPane $pane): self => $app,
        ));
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focus(ComponentId::named('pane'));
    }
}
