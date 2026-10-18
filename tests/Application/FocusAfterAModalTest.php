<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusAfterAModalTest extends TestCase
{
    #[Test]
    public function closingAModalGivesTheKeyboardBackToWhereItWasBefore(): void
    {
        $harness = ModelHarness::for(TabbedAppWithAModal::new(), width: 60, height: 12)->initialize();
        $harness->press('tab');
        self::assertSame('tabs/0', $harness->focused()?->value);

        $harness->press('ctrl+o');
        self::assertSame('name', $harness->focused()?->value);
        $harness->press('ctrl+o');

        self::assertSame('tabs/0', $harness->focused()?->value);
    }
}

final readonly class TabbedAppWithAModal extends App
{
    public function __construct(public TabBar $tabs, public TextInput $name, public bool $open = false)
    {
    }

    public static function new(): self
    {
        return new self(TabBar::new()->addTab('One')->add(TextInput::new())->addTab('Two')->add(TextInput::new()), TextInput::new());
    }

    public function view(): Widget
    {
        $main = Component::bind('tabs', $this->tabs, static fn (self $app, TabBar $tabs): self => new self($tabs, $app->name, $app->open));

        if (!$this->open) {
            return $main;
        }

        return Overlay::new($main, Component::bind(
            'modal',
            Modal::new('Name', Component::bind('name', $this->name, static fn (self $app, TextInput $name): self => new self($app->tabs, $name, $app->open))),
            static fn (self $app): self => $app,
        ));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->ctrl && $message->key === 'o'
            ? new UpdateResult(new self($this->tabs, $this->name, !$this->open))
            : UpdateResult::ignored($this);
    }
}
