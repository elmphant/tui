<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Rendering\Theme;

/**
 * A model that picks the theme it is drawn with, asked before every frame; null
 * keeps the one the program was started with.
 */
interface ChoosesItsTheme
{
    public function theme(): ?Theme;
}
