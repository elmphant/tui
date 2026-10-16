<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    #[Test]
    public function updateReceivesTheAvailableArea(): void
    {
        $model = new class implements Model {
            public ?Rect $lastArea = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $this->lastArea = $area;

                return UpdateResult::keep($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $area = Rect::fromSize(100, 30);

        $model->update(new TickMsg(0.1), $area);

        self::assertSame($area, $model->lastArea);
    }
}
