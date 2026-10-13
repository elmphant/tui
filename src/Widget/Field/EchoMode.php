<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

use Elmphant\Tui\Rendering\Grapheme;

enum EchoMode
{
    case Normal;
    case Password;
    case None;

    public function mask(string $text): string
    {
        return match ($this) {
            self::Normal => $text,
            self::Password => str_repeat('•', count(Grapheme::split($text))),
            self::None => '',
        };
    }
}
