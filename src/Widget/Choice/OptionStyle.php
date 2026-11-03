<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;

/**
 * @internal
 */
final class OptionStyle
{
    public static function of(Theme $theme, bool $isCursor, bool $isMarked, bool $focused): Style
    {
        return match (true) {
            $isCursor => $theme->cursorStyle,
            $isMarked => $theme->accent,
            $focused => $theme->base,
            default => $theme->dim,
        };
    }
}
