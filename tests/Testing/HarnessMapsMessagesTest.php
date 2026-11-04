<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessMapsMessagesTest extends TestCase
{
    #[Test]
    public function aMappedMessageArrivesWrappedAsTheRuntimeDeliversIt(): void
    {
        $model = new RecordingModel(Cmd::message(new TestMessage('ping'))->map(static fn (Message $message): Message => new Addressed('child', $message)));

        $received = ModelHarness::for($model)->init()->model()->received;

        self::assertContainsEquals(new Addressed('child', new TestMessage('ping')), $received);
    }

    #[Test]
    public function nestedMapsWrapTheInnerScopeFirst(): void
    {
        $inner = Cmd::batch(Cmd::message(new TestMessage('ping')))->map(static fn (Message $message): Message => new Addressed('inner', $message));
        $model = new RecordingModel($inner->map(static fn (Message $message): Message => new Addressed('outer', $message)));

        $received = ModelHarness::for($model)->init()->model()->received;

        self::assertContainsEquals(new Addressed('outer', new Addressed('inner', new TestMessage('ping'))), $received);
    }
}

final readonly class Addressed implements Message
{
    public function __construct(public string $to, public Message $message)
    {
    }
}

final class RecordingModel implements Model
{
    /**
     * @var list<Message>
     */
    public array $received = [];

    public function __construct(private readonly ?Cmd $init = null)
    {
    }

    public function init(): ?Cmd
    {
        return $this->init;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->received[] = $message;

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
