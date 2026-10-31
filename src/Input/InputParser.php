<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Text\Grapheme;

/**
 * @internal terminal-protocol parser consumed by Program
 */
final class InputParser
{
    public static function parseSequence(string $sequence): ?Message
    {
        if ($sequence === "\x1b[" || $sequence === "\x1bO") {
            return new KeyMsg($sequence[1], alt: true);
        }

        if (str_starts_with($sequence, "\x1b[")) {
            return self::parseSequenceCsi($sequence);
        }

        $result = self::parse($sequence, flush: true);

        return $result->messages[0] ?? null;
    }

    public static function parse(string $raw, bool $flush = false): InputParseResult
    {
        $messages = [];
        $length = strlen($raw);
        $position = 0;

        while ($position < $length) {
            $step = $raw[$position] === "\x1b"
                ? self::escapeAt($raw, $position, $length, $flush)
                : self::byteAt($raw, $position, $length);

            if ($step === null) {
                return self::incomplete($raw, $position, $length, $messages, $flush);
            }

            [$message, $position] = $step;
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return new InputParseResult($messages);
    }

    /**
     * Return the parsed message and next byte position, or null while the escape sequence is incomplete.
     *
     * @return array{KeyMsg|MouseMsg|null, int}|null
     */
    private static function escapeAt(string $raw, int $position, int $length, bool $flush): ?array
    {
        if ($position + 1 >= $length) {
            return $flush ? [new KeyMsg(Key::Escape), $position + 1] : null;
        }

        $next = $raw[$position + 1];

        if ($next === '[') {
            $decoded = self::parseCsi($raw, $position, $length);
            if ($decoded !== null) {
                return $decoded;
            }

            $end = self::csiEnd($raw, $position, $length);

            // Ignore complete terminal replies that do not represent keyboard input.
            return $end === null ? null : [null, $end];
        }

        if ($next === 'O') {
            if ($position + 2 >= $length) {
                return null;
            }

            $third = $raw[$position + 2];
            $key = self::ss3Key($third);
            if ($key !== null) {
                return [new KeyMsg($key, ctrl: $third >= 'a' && $third <= 'd'), $position + 3];
            }
        }

        $altByte = ord($next);
        if ($altByte >= 0x20 && $altByte < 0x7F) {
            return [new KeyMsg($next, alt: true), $position + 2];
        }

        // Legacy Alt+Enter arrives as ESC CR, so a control byte after ESC is an Alt combination.
        if ($altByte >= 0x01 && $altByte <= 0x1A || $altByte === 0x7F) {
            $namedKey = match ($altByte) {
                0x08, 0x7F => Key::Backspace,
                0x09 => Key::Tab,
                0x0A, 0x0D => Key::Enter,
                default => null,
            };

            return [
                $namedKey === null
                    ? new KeyMsg(chr($altByte + 0x60), ctrl: true, alt: true)
                    : new KeyMsg($namedKey, alt: true),
                $position + 2,
            ];
        }

        return [new KeyMsg(Key::Escape), $position + 1];
    }

    private static function ss3Key(string $third): Key|string|null
    {
        return match ($third) {
            'P' => Key::F1,
            'Q' => Key::F2,
            'R' => Key::F3,
            'S' => Key::F4,
            'H' => Key::Home,
            'F' => Key::End,
            'A', 'a' => Key::Up,
            'B', 'b' => Key::Down,
            'C', 'c' => Key::Right,
            'D', 'd' => Key::Left,
            'M' => Key::Enter,
            'j' => '*',
            'k' => '+',
            'l' => ',',
            'm' => '-',
            'n' => '.',
            'o' => '/',
            'X' => '=',
            'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y' => chr((ord($third) - 0x40) & 0xFF),
            default => null,
        };
    }

    /**
     * Parse a control key or character; return null while a UTF-8 character is incomplete.
     *
     * @return array{KeyMsg|null, int}|null
     */
    private static function byteAt(string $raw, int $position, int $length): ?array
    {
        $byte = ord($raw[$position]);

        if ($byte >= 0x80) {
            $characterLength = Grapheme::sequenceLength($byte);
            if ($position + $characterLength > $length) {
                return null;
            }
            $character = substr($raw, $position, $characterLength);

            // Drop malformed UTF-8 so invalid bytes cannot break grapheme operations in a model.
            return [mb_check_encoding($character, 'UTF-8') ? new KeyMsg($character) : null, $position + $characterLength];
        }

        $key = match (true) {
            $byte === 0x09 => new KeyMsg(Key::Tab),
            $byte === 0x0D, $byte === 0x0A => new KeyMsg(Key::Enter),
            $byte === 0x08, $byte === 0x7F => new KeyMsg(Key::Backspace),
            $byte >= 0x01 && $byte <= 0x1A => new KeyMsg(chr($byte + 0x60), ctrl: true),
            // NUL means Ctrl+Space; bytes 0x1C–0x1F mean Ctrl plus the symbol 0x40 higher.
            $byte <= 0x1F => new KeyMsg($byte === 0x00 ? ' ' : chr($byte + 0x40), ctrl: true),
            default => new KeyMsg($raw[$position]),
        };

        return [$key, $position + 1];
    }

    /**
     * @param list<KeyMsg|MouseMsg> $messages
     */
    private static function incomplete(string $raw, int $position, int $length, array $messages, bool $flush): InputParseResult
    {
        if (!$flush) {
            return new InputParseResult($messages, substr($raw, $position));
        }

        self::emitRemainderAsLiterals($raw, $position, $length, $messages);

        return new InputParseResult($messages);
    }

    private static function parseSequenceCsi(string $sequence): ?Message
    {
        $body = substr($sequence, 2);
        $final = $body[-1] ?? '';

        if ($final === 'u') {
            return self::parseKittyCsiU($body);
        }

        if (preg_match('/^27;(\d+);(\d+)~$/', $body, $modifyOtherKeys) === 1) {
            return self::parseKittyCsiU("{$modifyOtherKeys[2]};{$modifyOtherKeys[1]}u");
        }

        // `?`, `>` and `=` introduce device reports; `<` introduces mouse input.
        if (in_array($body[0] ?? '', ['?', '>', '='], true)) {
            return null;
        }

        // Skip unknown terminal replies so their bytes cannot become keystrokes.
        return self::parseCsi($sequence, 0, strlen($sequence))[0] ?? null;
    }

    private static function wheelAction(int $base): MouseAction
    {
        return match ($base) {
            0 => MouseAction::WheelUp,
            1 => MouseAction::WheelDown,
            2 => MouseAction::WheelLeft,
            default => MouseAction::WheelRight,
        };
    }

    private static function parseKittyCsiU(string $body): ?KeyMsg
    {
        $params = substr($body, 0, -1);
        $parts = explode(';', $params);

        $codepoint = (int)$parts[0];
        $modifierAndEvent = $parts[1] ?? '1';

        if (self::holdsAModifierWeCannotName($modifierAndEvent)) {
            return null;
        }

        [$shift, $alt, $ctrl, $eventType] = self::parseModifiers($modifierAndEvent);

        $key = match ($codepoint) {
            9 => Key::Tab,
            13, 57414 => Key::Enter,
            27 => Key::Escape,
            127 => Key::Backspace,
            57399, 57400, 57401, 57402, 57403, 57404, 57405, 57406, 57407, 57408 => chr((ord('0') + $codepoint - 57399) & 0xFF),
            57409 => '.',
            57410 => '/',
            57411 => '*',
            57412 => '-',
            57413 => '+',
            57415 => '=',
            57417 => Key::Left,
            57418 => Key::Right,
            57419 => Key::Up,
            57420 => Key::Down,
            57421 => Key::PageUp,
            57422 => Key::PageDown,
            57423 => Key::Home,
            57424 => Key::End,
            57425 => Key::Insert,
            57426 => Key::Delete,
            default => self::isPrintable($codepoint) ? mb_chr($codepoint, 'UTF-8') : null,
        };

        if ($key === null) {
            return null;
        }

        return new KeyMsg($key, ctrl: $ctrl, alt: $alt, shift: $shift, eventType: $eventType);
    }

    /**
     * Unknown Kitty private-use codepoints represent unnamed functional keys, never text.
     */
    private static function isPrintable(int $codepoint): bool
    {
        return $codepoint >= 32
            && ($codepoint < 127 || $codepoint >= 160)
            && ($codepoint < 0xD800 || $codepoint > 0xDFFF)
            && ($codepoint < 0xE000 || $codepoint > 0xF8FF)
            && $codepoint <= 0x10FFFF;
    }

    /**
     * @param list<KeyMsg|MouseMsg> &$messages
     */
    private static function emitRemainderAsLiterals(string $raw, int $position, int $length, array &$messages): void
    {
        while ($position < $length) {
            $byte = ord($raw[$position]);
            if ($byte === 0x1B) {
                $messages[] = new KeyMsg(Key::Escape);
            } elseif ($byte >= 0x80) {
                $messages[] = new KeyMsg("\u{FFFD}");
            } else {
                $messages[] = new KeyMsg($raw[$position]);
            }
            ++$position;
        }
    }

    private static function csiEnd(string $raw, int $escapePosition, int $length): ?int
    {
        $position = $escapePosition + 2;

        // An incomplete X10 report has three raw payload bytes, any of which may look like a final byte.
        if (($raw[$position] ?? '') === 'M') {
            return null;
        }

        while ($position < $length) {
            $byte = ord($raw[$position]);
            if ($byte >= 0x40 && $byte <= 0x7E) {
                return $position + 1;
            }
            ++$position;
        }

        return null;
    }

    /**
     * @return array{KeyMsg|MouseMsg, int}|null
     */
    private static function parseCsi(string $raw, int $position, int $length): ?array
    {
        $position += 2;

        if ($position < $length && $raw[$position] === '<') {
            return self::parseSgrMouse($raw, $position + 1, $length);
        }

        if ($position < $length && $raw[$position] === 'M') {
            return self::parseX10Mouse($raw, $position + 1, $length);
        }

        $parameters = '';

        while ($position < $length) {
            $byte = ord($raw[$position]);
            if ($byte >= 0x40 && $byte <= 0x7E) {
                break;
            }
            $parameters .= $raw[$position];
            ++$position;
        }

        if ($position >= $length) {
            return null;
        }

        $finalCharacter = $raw[$position];
        ++$position;

        // F3 has no parameters here; a cursor-position report uses the same final byte.
        if ($finalCharacter === 'R' && str_contains($parameters, ';')) {
            return null;
        }

        $shift = false;
        $alt = false;
        $ctrl = false;
        $eventType = KeyEventType::Press;
        if (str_contains($parameters, ';')) {
            $parameterParts = explode(';', $parameters);
            if (count($parameterParts) >= 2) {
                if (self::holdsAModifierWeCannotName($parameterParts[count($parameterParts) - 1])) {
                    return null;
                }
                [$shift, $alt, $ctrl, $eventType] = self::parseModifiers($parameterParts[count($parameterParts) - 1]);
                $parameters = $parameterParts[0];
            }
        }

        $key = match ($finalCharacter) {
            'A' => Key::Up,
            'B' => Key::Down,
            'C' => Key::Right,
            'D' => Key::Left,
            'H' => Key::Home,
            'F' => Key::End,
            'Z' => Key::Tab,
            'P' => Key::F1,
            'Q' => Key::F2,
            'R' => Key::F3,
            'S' => Key::F4,
            'a' => Key::Up,
            'b' => Key::Down,
            'c' => Key::Right,
            'd' => Key::Left,
            '~' => match ($parameters) {
                '1', '7' => Key::Home,
                '4', '8' => Key::End,
                '2' => Key::Insert,
                '3' => Key::Delete,
                '5' => Key::PageUp,
                '6' => Key::PageDown,
                '11' => Key::F1,
                '12' => Key::F2,
                '13' => Key::F3,
                '14' => Key::F4,
                '15' => Key::F5,
                '17' => Key::F6,
                '18' => Key::F7,
                '19' => Key::F8,
                '20' => Key::F9,
                '21' => Key::F10,
                '23' => Key::F11,
                '24' => Key::F12,
                default => null,
            },
            default => null,
        };

        if ($key === null) {
            return null;
        }

        if ($finalCharacter === 'Z' || ($finalCharacter >= 'a' && $finalCharacter <= 'd')) {
            $shift = true;
        }

        return [new KeyMsg($key, ctrl: $ctrl, alt: $alt, shift: $shift, eventType: $eventType), $position];
    }

    /**
     * Reject unsupported modifiers so Cmd+A cannot become plain `a`; Caps Lock and Num Lock are not modifiers.
     */
    private static function holdsAModifierWeCannotName(string $modifierParam): bool
    {
        $parts = explode(':', $modifierParam);
        $modifierValue = max(0, (int)$parts[0] - 1);

        return ($modifierValue & (8 | 16 | 32)) !== 0;
    }

    /**
     * @return array{bool, bool, bool, KeyEventType} [shift, alt, ctrl, eventType]
     */
    private static function parseModifiers(string $modifierParam): array
    {
        $parts = explode(':', $modifierParam);
        $modifierValue = max(0, (int)$parts[0] - 1);
        $eventType = match ($parts[1] ?? null) {
            '2' => KeyEventType::Repeat,
            '3' => KeyEventType::Release,
            default => KeyEventType::Press,
        };

        return [
            (bool)($modifierValue & 1),
            (bool)($modifierValue & 2),
            (bool)($modifierValue & 4),
            $eventType,
        ];
    }

    /**
     * @return array{MouseMsg, int}|null
     */
    private static function parseX10Mouse(string $raw, int $position, int $length): ?array
    {
        if ($position + 3 > $length) {
            return null;
        }

        $buttonCode = ord($raw[$position]) - 32;
        $shift = ($buttonCode & 4) !== 0;
        $alt = ($buttonCode & 8) !== 0;
        $ctrl = ($buttonCode & 16) !== 0;
        $column = max(0, ord($raw[$position + 1]) - 33);
        $row = max(0, ord($raw[$position + 2]) - 33);
        $position += 3;

        if (($buttonCode & 64) !== 0) {
            return [new MouseMsg(
                $column,
                $row,
                self::wheelAction($buttonCode & 3),
                MouseButton::None,
                shift: $shift,
                alt: $alt,
                ctrl: $ctrl,
            ), $position];
        }

        $buttonBase = $buttonCode & 3;
        if ($buttonBase === 3) {
            return [new MouseMsg($column, $row, MouseAction::Release, MouseButton::None, shift: $shift, alt: $alt, ctrl: $ctrl), $position];
        }

        $button = match ($buttonBase) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            default => MouseButton::Right,
        };

        $action = ($buttonCode & 32) !== 0 ? MouseAction::Motion : MouseAction::Press;

        return [new MouseMsg($column, $row, $action, $button, shift: $shift, alt: $alt, ctrl: $ctrl), $position];
    }

    /**
     * @return array{MouseMsg, int}|null
     */
    private static function parseSgrMouse(string $raw, int $position, int $length): ?array
    {
        $parameters = '';
        while ($position < $length) {
            $character = $raw[$position];
            if ($character === 'M' || $character === 'm') {
                break;
            }
            $parameters .= $character;
            ++$position;
        }

        if ($position >= $length) {
            return null;
        }

        $isRelease = $raw[$position] === 'm';
        ++$position;

        if (preg_match('/^(\d+);(\d+);(\d+)$/', $parameters, $fields) !== 1) {
            return null;
        }

        $buttonCode = (int)$fields[1];
        // Buttons 8 to 11 (back, forward and more) set bit 128; nothing here can name them.
        if (($buttonCode & 128) !== 0) {
            return null;
        }
        $shift = ($buttonCode & 4) !== 0;
        $alt = ($buttonCode & 8) !== 0;
        $ctrl = ($buttonCode & 16) !== 0;
        $column = max(0, (int)$fields[2] - 1);
        $row = max(0, (int)$fields[3] - 1);
        $hasMotion = (bool)($buttonCode & 32);
        $buttonBase = $buttonCode & 3;

        if (($buttonCode & 64) !== 0) {
            return [new MouseMsg(
                $column,
                $row,
                self::wheelAction($buttonBase),
                MouseButton::None,
                shift: $shift,
                alt: $alt,
                ctrl: $ctrl,
            ), $position];
        }

        $button = match ($buttonBase) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            2 => MouseButton::Right,
            default => MouseButton::None,
        };

        $action = match (true) {
            $hasMotion => MouseAction::Motion,
            $isRelease => MouseAction::Release,
            default => MouseAction::Press,
        };

        return [new MouseMsg($column, $row, $action, $button, shift: $shift, alt: $alt, ctrl: $ctrl), $position];
    }
}
