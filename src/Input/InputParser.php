<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Grapheme;

/**
 * @internal terminal-protocol parser consumed by Program
 */
final class InputParser
{
    public static function parseSequence(string $sequence): ?Message
    {
        if (str_starts_with($sequence, "\x1b[")) {
            return self::parseSequenceCsi($sequence);
        }

        $result = self::parse($sequence, flush: true);

        return $result->messages[0] ?? null;
    }

    public static function parse(string $raw, bool $flush = false): InputParseResult
    {
        if ($raw === '') {
            return new InputParseResult([]);
        }

        $messages = [];
        $length = strlen($raw);
        $position = 0;

        while ($position < $length) {
            $byte = ord($raw[$position]);

            if ($byte === 0x1B) {
                if ($position + 1 >= $length) {
                    if ($flush) {
                        $messages[] = new KeyMsg(Key::Escape);
                        ++$position;
                        continue;
                    }

                    return new InputParseResult($messages, substr($raw, $position));
                }

                $next = $raw[$position + 1];

                if ($next === '[') {
                    $result = self::parseCsi($raw, $position, $length);
                    if ($result !== null) {
                        $messages[] = $result[0];
                        $position = $result[1];
                        continue;
                    }
                    if (self::isCsiIncomplete($raw, $position, $length)) {
                        if ($flush) {
                            self::emitRemainderAsLiterals($raw, $position, $length, $messages);

                            return new InputParseResult($messages);
                        }

                        return new InputParseResult($messages, substr($raw, $position));
                    }
                }

                if ($next === 'O') {
                    if ($position + 2 >= $length) {
                        if ($flush) {
                            self::emitRemainderAsLiterals($raw, $position, $length, $messages);

                            return new InputParseResult($messages);
                        }

                        return new InputParseResult($messages, substr($raw, $position));
                    }
                    $third = $raw[$position + 2];
                    $key = match ($third) {
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
                    if ($key !== null) {
                        $messages[] = new KeyMsg($key, ctrl: $third >= 'a' && $third <= 'd');
                        $position += 3;
                        continue;
                    }
                }

                $altByte = ord($next);
                if ($altByte >= 0x20 && $altByte < 0x7F) {
                    $messages[] = new KeyMsg($next, alt: true);
                    $position += 2;
                    continue;
                }

                $messages[] = new KeyMsg(Key::Escape);
                ++$position;
                continue;
            }

            if ($byte === 0x09) {
                $messages[] = new KeyMsg(Key::Tab);
                ++$position;
                continue;
            }

            if ($byte === 0x0D || $byte === 0x0A) {
                $messages[] = new KeyMsg(Key::Enter);
                ++$position;
                continue;
            }

            if ($byte === 0x08 || $byte === 0x7F) {
                $messages[] = new KeyMsg(Key::Backspace);
                ++$position;
                continue;
            }

            if ($byte >= 0x01 && $byte <= 0x1A) {
                $messages[] = new KeyMsg(chr($byte + 0x60), ctrl: true);
                ++$position;
                continue;
            }

            if ($byte >= 0x80) {
                $characterLength = Grapheme::sequenceLength($byte);
                if ($position + $characterLength > $length) {
                    if ($flush) {
                        self::emitRemainderAsLiterals($raw, $position, $length, $messages);

                        return new InputParseResult($messages);
                    }

                    return new InputParseResult($messages, substr($raw, $position));
                }
                $character = substr($raw, $position, $characterLength);
                $messages[] = new KeyMsg($character);
                $position += $characterLength;
                continue;
            }

            $messages[] = new KeyMsg($raw[$position]);
            ++$position;
        }

        return new InputParseResult($messages);
    }

    private static function parseSequenceCsi(string $sequence): ?Message
    {
        $body = substr($sequence, 2);
        $final = $body[-1] ?? '';

        if ($final === 'u') {
            return self::parseKittyCsiU($body);
        }

        if ($sequence === "\x1b[I") {
            return new FocusMsg(true);
        }

        if ($sequence === "\x1b[O") {
            return new FocusMsg(false);
        }

        // `?`, `>` and `=` open a device report. Only `<` belongs to a reader, on a
        // mouse press.
        if (self::endsACsiSequence($final) && in_array($body[0] ?? '', ['?', '>', '='], true)) {
            return null;
        }

        $decoded = self::parseCsi($sequence, 0, strlen($sequence));

        if ($decoded !== null) {
            return $decoded[0];
        }

        // A finished sequence we do not know is the terminal answering a question, and
        // reading its bytes as text invents a keystroke. An unfinished one is still text.
        if (self::endsACsiSequence($final)) {
            return null;
        }

        return self::parse($sequence, flush: true)->messages[0] ?? null;
    }

    private static function endsACsiSequence(string $byte): bool
    {
        return $byte !== '' && ord($byte) >= 0x40 && ord($byte) <= 0x7E;
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
            default => ($codepoint >= 32 && $codepoint < 127) ? chr($codepoint) : null,
        };

        if ($key === null) {
            return null;
        }

        return new KeyMsg($key, ctrl: $ctrl, alt: $alt, shift: $shift, eventType: $eventType);
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

    private static function isCsiIncomplete(string $raw, int $escapePosition, int $length): bool
    {
        $position = $escapePosition + 2;
        if ($position < $length && $raw[$position] === '<') {
            ++$position;
        }
        while ($position < $length) {
            $byte = ord($raw[$position]);
            if ($byte >= 0x40 && $byte <= 0x7E) {
                return false;
            }
            ++$position;
        }

        return true;
    }

    /**
     * @return array{KeyMsg|MouseMsg, int}|null
     */
    private static function parseCsi(string $raw, int $position, int $length): ?array
    {
        $start = $position;
        $position += 2;

        if ($position < $length && $raw[$position] === '<') {
            return self::parseSgrMouse($raw, $start, $position + 1, $length);
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
            if ($position - $start > 24) {
                break;
            }
        }

        if ($position >= $length) {
            return null;
        }

        $finalCharacter = $raw[$position];
        ++$position;

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
        $eventType = match ((int)($parts[1] ?? 1)) {
            2 => KeyEventType::Repeat,
            3 => KeyEventType::Release,
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
            $action = ($buttonCode & 3) === 0 ? MouseAction::WheelUp : MouseAction::WheelDown;

            return [new MouseMsg($column, $row, $action, MouseButton::None, shift: $shift, alt: $alt, ctrl: $ctrl), $position];
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
    private static function parseSgrMouse(string $raw, int $start, int $position, int $length): ?array
    {
        $parameters = '';
        while ($position < $length) {
            $character = $raw[$position];
            if ($character === 'M' || $character === 'm') {
                break;
            }
            $parameters .= $character;
            ++$position;
            if ($position - $start > 32) {
                return null;
            }
        }

        if ($position >= $length) {
            return null;
        }

        $isRelease = $raw[$position] === 'm';
        ++$position;

        $parts = explode(';', $parameters);
        if (count($parts) !== 3) {
            return null;
        }

        $buttonCode = (int)$parts[0];
        $shift = ($buttonCode & 4) !== 0;
        $alt = ($buttonCode & 8) !== 0;
        $ctrl = ($buttonCode & 16) !== 0;
        $column = (int)$parts[1] - 1;
        $row = (int)$parts[2] - 1;
        $hasMotion = (bool)($buttonCode & 32);
        $buttonBase = $buttonCode & 3;

        if (($buttonCode & 64) !== 0) {
            return [new MouseMsg(
                $column,
                $row,
                $buttonBase === 0 ? MouseAction::WheelUp : MouseAction::WheelDown,
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
