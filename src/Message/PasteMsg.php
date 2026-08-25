<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

final readonly class PasteMsg implements Message
{
    public string $content;

    public function __construct(string $content)
    {
        $this->content = self::textOnly($content);
    }

    /**
     * A paste arrives as bytes in whatever encoding its source had, and one byte
     * that cannot be a character is enough to make every UTF-8-aware call on the
     * whole paste answer nothing — a field reading it would lose the paste
     * entire rather than the byte.
     */
    private static function textOnly(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $substitute = mb_substitute_character();
        mb_substitute_character('none');

        try {
            return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }
    }
}
