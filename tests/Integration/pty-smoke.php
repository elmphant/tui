<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;

$model = new class implements Model {
    public function init(): ?Cmd
    {
        return Cmd::quit();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderContext $context): void
    {
    }
};

Program::new($model)->withAltScreen()->withMouseTracking()->start();
