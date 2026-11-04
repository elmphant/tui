<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WhereTheKeyboardLandsTest extends TestCase
{
    #[Test]
    public function closingAModalLandsOnTheLastStopHeldBeforeItRatherThanAnEarlierOne(): void
    {
        $harness = ModelHarness::for(ThreeFieldsAndAModal::new(), width: 40, height: 12)->init();
        $harness->press('tab', 'tab');
        self::assertSame('c', $harness->focused()?->value);

        $harness->press('ctrl+o');
        self::assertSame('name', $harness->focused()?->value);
        $harness->press('ctrl+o');

        self::assertSame('c', $harness->focused()?->value);
    }

    #[Test]
    public function aStopThatLeavesLandsInTheInnermostPaneItSatInRatherThanTheOuterOne(): void
    {
        $harness = ModelHarness::for(NestedPanes::new(), width: 40, height: 16)->init();
        self::assertSame('z', $harness->focused()?->value);

        $harness->press('ctrl+o');

        self::assertSame('y', $harness->focused()?->value);
    }
}

final readonly class ThreeFieldsAndAModal extends App
{
    use Cloneable;

    public function __construct(public TextInput $a, public TextInput $b, public TextInput $c, public TextInput $name, public bool $open = false)
    {
    }

    public static function new(): self
    {
        return new self(TextInput::new(), TextInput::new(), TextInput::new(), TextInput::new());
    }

    public function view(): Widget
    {
        $main = Layout::vertical(
            Component::bind('a', $this->a, static fn (self $app, TextInput $a): self => $app->cloneWith(a: $a)),
            Component::bind('b', $this->b, static fn (self $app, TextInput $b): self => $app->cloneWith(b: $b)),
            Component::bind('c', $this->c, static fn (self $app, TextInput $c): self => $app->cloneWith(c: $c)),
        );

        if (!$this->open) {
            return $main;
        }

        return Overlay::new($main, Component::bind(
            'modal',
            Modal::new('Name', Component::bind('name', $this->name, static fn (self $app, TextInput $name): self => $app->cloneWith(name: $name))),
            static fn (self $app): self => $app,
        ));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->ctrl && $message->key === 'o'
            ? new UpdateResult($this->cloneWith(open: !$this->open))
            : UpdateResult::ignored($this);
    }
}

final readonly class NestedPanes extends App
{
    use Cloneable;

    public function __construct(public TextInput $x, public TextInput $y, public TextInput $z, public bool $zGone = false)
    {
    }

    public static function new(): self
    {
        return new self(TextInput::new(), TextInput::new(), TextInput::new());
    }

    public function view(): Widget
    {
        $inner = Panel::new('inner')->add(Component::bind('y', $this->y, static fn (self $app, TextInput $y): self => $app->cloneWith(y: $y)));
        if (!$this->zGone) {
            $inner = $inner->add(Component::bind('z', $this->z, static fn (self $app, TextInput $z): self => $app->cloneWith(z: $z)));
        }

        return Component::bind(
            'outer',
            Panel::new('outer')
                ->add(Component::bind('x', $this->x, static fn (self $app, TextInput $x): self => $app->cloneWith(x: $x)))
                ->add(Component::bind('inner', $inner, static fn (self $app): self => $app)),
            static fn (self $app): self => $app,
        );
    }

    protected function setup(): Cmd
    {
        return Cmd::focus(ComponentId::named('z'));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->ctrl && $message->key === 'o'
            ? new UpdateResult($this->cloneWith(zGone: true))
            : UpdateResult::ignored($this);
    }
}
