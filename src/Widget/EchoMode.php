<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

enum EchoMode
{
    case Normal;
    case Password;
    case None;

    public function mask(string $text): string
    {
        return match ($this) {
            self::Normal => $text,
            self::Password => str_repeat('•', mb_strlen($text)),
            self::None => '',
        };
    }
}
