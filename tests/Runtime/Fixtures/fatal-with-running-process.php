<?php

declare(strict_types=1);

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$model = new class($argv[1]) implements Model {
    public function __construct(private readonly string $seconds)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::exec(['sleep', $this->seconds]);
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

Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->start();
