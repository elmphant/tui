<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Widget\Widget;

final readonly class NamedOnlyApp extends App
{
    public function __construct(private ArrayObject $seen)
    {
    }

    public function view(): Widget
    {
        return Component::named('pane', new RecordingPane($this->seen));
    }
}
