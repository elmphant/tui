<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
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
    public function runCallsAsTheFirstThingRunsTheCallSetupAskedFor(): void
    {
        $harness = ModelHarness::for(new LoadsOnSetup())->runCalls();

        self::assertSame('loaded', $harness->model()->status);
    }

    #[Test]
    public function runFibersAsTheFirstThingRunsTheFiberSetupAskedFor(): void
    {
        $harness = ModelHarness::for(new LoadsOnSetup(fiber: true))->runFibers();

        self::assertSame('fetched', $harness->model()->status);
    }

    #[Test]
    public function answerAsTheFirstThingEndsTheCommandSetupStarted(): void
    {
        $harness = ModelHarness::for(new LoadsOnSetup())->answer('list', stdout: 'listed');

        self::assertSame('listed', $harness->model()->listed);
    }

    #[Test]
    public function issuedAsTheFirstThingListsWhatSetupAskedFor(): void
    {
        self::assertCount(1, ModelHarness::for(new LoadsOnSetup())->issued(ExecCmd::class));
    }

    #[Test]
    public function anIssuedCommandEqualsTheOneCmdExecBuilds(): void
    {
        self::assertEquals(
            [Cmd::exec(['ls'], EffectId::named('list'))],
            ModelHarness::for(new LoadsOnSetup())->issued(ExecCmd::class),
        );
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

final readonly class LoadsOnSetup extends App
{
    public function __construct(public string $status = 'loading', public string $listed = '', private bool $fiber = false)
    {
    }

    public function view(): Widget
    {
        return Text::new($this->status);
    }

    protected function setup(): ?Cmd
    {
        if ($this->fiber) {
            return Cmd::fiber(static fn (): Message => new TestMessage('fetched'));
        }

        return Cmd::batch(
            Cmd::call(static fn (): Message => new TestMessage('loaded')),
            Cmd::exec(['ls'], EffectId::named('list')),
        );
    }

    protected function onMessage(Message $message, InputContext $input): self
    {
        return match (true) {
            $message instanceof TestMessage => $this->cloneWith(status: $message->value),
            $message instanceof ProcessResultMsg => $this->cloneWith(listed: $message->stdout),
            default => $this,
        };
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
