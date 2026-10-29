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
     * The message an escape at $position starts, if any, and where the next
     * one begins; null while the sequence is still arriving.
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

            // Whole, and no key of ours: the terminal answering a question
            // rather than anything the reader pressed.
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

        // A control byte after ESC names its alt-combo rather than a lone
        // escape — legacy alt+Enter arrives as ESC CR.
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
     * Any byte but escape: a control key, a character, or nothing for a byte
     * no keyboard produces; null while a UTF-8 character is still arriving.
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

            // A continuation byte on its own, or a sequence that never finished.
            // Passing it on would put invalid UTF-8 into a model, where mb_/grapheme
            // calls answer nothing at all.
            return [mb_check_encoding($character, 'UTF-8') ? new KeyMsg($character) : null, $position + $characterLength];
        }

        $key = match (true) {
            $byte === 0x09 => new KeyMsg(Key::Tab),
            $byte === 0x0D, $byte === 0x0A => new KeyMsg(Key::Enter),
            $byte === 0x08, $byte === 0x7F => new KeyMsg(Key::Backspace),
            $byte >= 0x01 && $byte <= 0x1A => new KeyMsg(chr($byte + 0x60), ctrl: true),
            // What is left below space: a terminal sends NUL for ctrl+space, and
            // the four bytes above escape for ctrl with the symbol 0x40 higher.
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

        // `?`, `>` and `=` open a device report. Only `<` belongs to a reader, on a
        // mouse press.
        if (in_array($body[0] ?? '', ['?', '>', '='], true)) {
            return null;
        }

        // A sequence we do not know is the terminal answering a question, and
        // reading its bytes as text invents a keystroke.
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
            9, 57346 => Key::Tab,
            13, 57345 => Key::Enter,
            27, 57344 => Key::Escape,
            127, 57347 => Key::Backspace,
            57355 => Key::Insert,
            57356 => Key::Delete,
            57357 => Key::Left,
            57358 => Key::Right,
            57359 => Key::Up,
            57360 => Key::Down,
            57361 => Key::PageUp,
            57362 => Key::PageDown,
            57363 => Key::Home,
            57364 => Key::End,
            57376 => Key::F1,
            57377 => Key::F2,
            57378 => Key::F3,
            57379 => Key::F4,
            57380 => Key::F5,
            57381 => Key::F6,
            57382 => Key::F7,
            57383 => Key::F8,
            57384 => Key::F9,
            57385 => Key::F10,
            57386 => Key::F11,
            57387 => Key::F12,
            57399, 57400, 57401, 57402, 57403, 57404, 57405, 57406, 57407, 57408 => chr((ord('0') + $codepoint - 57399) & 0xFF),
            57409 => '.',
            57410 => '/',
            57411 => '*',
            57412 => '-',
            57413 => '+',
            57414 => Key::Enter,
            57415 => '=',
            default => self::isPrintable($codepoint) ? mb_chr($codepoint, 'UTF-8') : null,
        };

        if ($key === null) {
            return null;
        }

        return new KeyMsg($key, ctrl: $ctrl, alt: $alt, shift: $shift, eventType: $eventType);
    }

    /**
     * Kitty sends the functional keys it has no legacy code for as private-use codepoints, so an unknown one is a key
     * this parser cannot name, never text.
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

    /**
     * Where a CSI sequence ends, or null while the rest of it is still in flight.
     */
    private static function csiEnd(string $raw, int $escapePosition, int $length): ?int
    {
        $position = $escapePosition + 2;

        // Only an X10 mouse report whose three raw bytes are still arriving gets
        // here, and any of them may look like a final byte itself.
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

        // A cursor-position report shares this final byte; only an unmodified
        // F3 arrives without parameters.
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
     * Super, hyper and meta have nowhere to go in a KeyMsg, and ignoring the bit would
     * deliver cmd+a as a plain "a". Caps and num lock are locks, not modifiers.
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
        $column = ord($raw[$position + 1]) - 33;
        $row = ord($raw[$position + 2]) - 33;
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
        $shift = ($buttonCode & 4) !== 0;
        $alt = ($buttonCode & 8) !== 0;
        $ctrl = ($buttonCode & 16) !== 0;
        $column = (int)$fields[2] - 1;
        $row = (int)$fields[3] - 1;
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
