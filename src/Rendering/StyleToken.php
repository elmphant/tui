<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

enum StyleToken
{
    case Base;
    case Accent;
    case Dim;
    case Error;
    case Success;
    case Warning;
    case Info;
    case Title;

    public function resolve(Theme $theme): Style
    {
        return match ($this) {
            self::Base => $theme->base,
            self::Accent => $theme->accent,
            self::Dim => $theme->dim,
            self::Error => $theme->error,
            self::Success => $theme->success,
            self::Warning => $theme->warning,
            self::Info => $theme->info,
            self::Title => $theme->titleStyle,
        };
    }
}
