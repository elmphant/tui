<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\Drawable;

interface Widget extends Drawable
{
    public function sizing(): Sizing;
}
