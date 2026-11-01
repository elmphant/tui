<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application\Support;

use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

final readonly class TwoLineApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Paragraph::fromText("first\nsecond");
    }
}
