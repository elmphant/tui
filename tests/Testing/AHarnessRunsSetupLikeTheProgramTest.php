<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AHarnessRunsSetupLikeTheProgramTest extends TestCase
{
    #[Test]
    public function theFirstKeyGoesWhereSetupPutTheKeyboard(): void
    {
        $harness = ModelHarness::for(new SecondFieldFirst(TextInput::new(), TextInput::new()), width: 30, height: 3);

        $harness->type('x');

        self::assertSame('x', $harness->model()->second->value());
    }

    #[Test]
    public function anExplicitInitRunsSetupOnce(): void
    {
        $harness = ModelHarness::for(new SecondFieldFirst(TextInput::new(), TextInput::new()), width: 30, height: 3)->init();

        $harness->type('x');

        self::assertSame('x', $harness->model()->second->value());
        self::assertSame('', $harness->model()->first->value());
    }
}

final readonly class SecondFieldFirst extends App
{
    public function __construct(public TextInput $first, public TextInput $second)
    {
    }

    public function view(): Widget
    {
        return Layout::column($this->bind('first'), $this->bind('second'));
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focus(ComponentId::named('second'));
    }
}
