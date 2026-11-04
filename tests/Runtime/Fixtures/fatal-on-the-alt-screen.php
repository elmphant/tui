<?php

declare(strict_types=1);

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Testing\VirtualTerminal;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$model = new class implements Model {
    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        str_repeat('x', 1 << 30);

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
};

Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->withScreenMode(ScreenMode::alternate())->start();
