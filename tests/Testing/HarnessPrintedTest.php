<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Testing\ModelHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessPrintedTest extends TestCase
{
    #[Test]
    public function theLinesAModelPrintedAreListedInOrder(): void
    {
        $harness = ModelHarness::for(new PrintingModel(), 20, 3)->press('a', 'b');

        self::assertSame(['typed a', 'typed b', 'and done'], $harness->printed());
    }
}

final readonly class PrintingModel implements Model
{
    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        return new UpdateResult($this, $message->key === 'b' ? Cmd::println('typed b', 'and done') : Cmd::println("typed {$message->key}"));
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
