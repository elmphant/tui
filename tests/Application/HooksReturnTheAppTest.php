<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HooksReturnTheAppTest extends TestCase
{
    #[Test]
    public function aHookMayAnswerWithTheAppAlone(): void
    {
        $harness = ModelHarness::for(new BareHooksApp(), 20, 2)->init();

        $harness->press('x');
        $harness->dispatch(new TickMsg(1.0));
        $harness->dispatch(new BareNote());

        $model = $harness->model();
        self::assertInstanceOf(BareHooksApp::class, $model);
        self::assertSame(['key', 'tick', 'message'], $model->heard);
    }
}

final readonly class BareNote implements Message
{
}

final readonly class BareHooksApp extends App
{
    /**
     * @param list<string> $heard
     */
    public function __construct(public array $heard = [])
    {
    }

    public function view(): Widget
    {
        return Text::new('hooks');
    }

    protected function onKey(KeyMsg $message, InputContext $input): self
    {
        return new self([...$this->heard, 'key']);
    }

    protected function onTick(TickMsg $message, InputContext $input): self
    {
        return new self([...$this->heard, 'tick']);
    }

    protected function onMessage(Message $message, InputContext $input): self
    {
        return $message instanceof BareNote ? new self([...$this->heard, 'message']) : $this;
    }
}
