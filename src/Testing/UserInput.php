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

/**
 * The messages a reader's keys and pointer produce, for a test that calls
 * update() itself. ModelHarness sends the same ones.
 */
final class UserInput
{
    /**
     * A key in the spelling Keybindings use: 'tab', 'shift+tab', 'ctrl+r', 'enter'.
     */
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

    public static function click(int $column, int $row): MouseMsg
    {
        return new MouseMsg($column, $row, MouseAction::Press, MouseButton::Left);
    }

    public static function release(int $column, int $row): MouseMsg
    {
        return new MouseMsg($column, $row, MouseAction::Release, MouseButton::Left);
    }

    /**
     * The pointer moving with the left button held.
     */
    public static function drag(int $column, int $row): MouseMsg
    {
        return new MouseMsg($column, $row, MouseAction::Motion, MouseButton::Left);
    }

    public static function paste(string $text): PasteMsg
    {
        return new PasteMsg($text);
    }

    /**
     * Notches down, or up where negative.
     */
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
