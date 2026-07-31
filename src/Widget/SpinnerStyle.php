<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

enum SpinnerStyle
{
    case Braille;
    case Dots;
    case Line;

    /**
     * @return list<string>
     */
    public function frames(): array
    {
        return match ($this) {
            self::Braille => ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'],
            self::Dots => ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'],
            self::Line => ['-', '\\', '|', '/'],
        };
    }
}
