<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;

interface Model extends Reducer
{
    public function init(): ?Cmd;

    public function render(Frame $frame, RenderPass $pass): void;
}
