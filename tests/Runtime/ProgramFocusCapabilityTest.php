<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\RoutesFocus;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramFocusCapabilityTest extends TestCase
{
    #[Test]
    public function anyModelThatRoutesFocusIsToldWhereTheKeyboardIs(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('q');

        $final = Program::new(new FocusRoutingModel())->withTerminal($terminal)->start();

        self::assertInstanceOf(FocusRoutingModel::class, $final);
        self::assertEquals(ComponentId::named('pane'), $final->seen);
    }
}

final class FocusRoutingModel implements RoutesFocus
{
    public function __construct(public readonly ?ComponentId $seen = null)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::focus(ComponentId::named('pane'));
    }

    public function update(Message $message, Rect $area, ?ComponentId $focused = null): UpdateResult
    {
        return $message instanceof KeyMsg && 'q' === $message->key
            ? new UpdateResult(new self($focused), Cmd::quit())
            : new UpdateResult($this);
    }

    /**
     * @return list<ComponentId>
     */
    public function focusableIds(): array
    {
        return [ComponentId::named('pane')];
    }

    public function render(Frame $frame, RenderContext $context): void
    {
    }
}
