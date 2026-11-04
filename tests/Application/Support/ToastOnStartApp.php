<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application\Support;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ToastPosition;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

final readonly class ToastOnStartApp extends App
{
    public function view(): Widget
    {
        return Layout::column(...array_map(static fn (int $line): Paragraph => Paragraph::fromText("line {$line}"), range(1, 5)));
    }

    protected function setup(): ?Cmd
    {
        return Cmd::toast('saved')->withPosition(ToastPosition::BottomRight);
    }
}
