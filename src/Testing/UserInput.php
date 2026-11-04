<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Text\Grapheme;

final class UserInput
{
    public static function key(string $pattern): KeyMsg
    {
        $key = KeyPattern::fromString($pattern);

        return new KeyMsg($key->key, $key->ctrl, $key->alt, $key->shift);
    }

    /**
     * @return list<KeyMsg>
     */
    public static function typed(string $text): array
    {
        return array_map(static fn (string $grapheme): KeyMsg => new KeyMsg($grapheme), Grapheme::split($text));
    }

    public static function press(int $column, int $row): MouseMsg
    {
        return new MouseMsg($column, $row, MouseAction::Press, MouseButton::Left);
    }

    public static function release(int $column, int $row): MouseMsg
    {
        return new MouseMsg($column, $row, MouseAction::Release, MouseButton::Left);
    }

    public static function drag(int $column, int $row): MouseMsg
    {
        return new MouseMsg($column, $row, MouseAction::Motion, MouseButton::Left);
    }

    public static function paste(string $text): PasteMsg
    {
        return new PasteMsg($text);
    }

    public static function wheel(int $column, int $row, int $notches): MouseMsg
    {
        return new MouseMsg(
            $column,
            $row,
            $notches < 0 ? MouseAction::WheelUp : MouseAction::WheelDown,
            MouseButton::None,
            repeat: max(1, abs($notches)),
        );
    }
}
