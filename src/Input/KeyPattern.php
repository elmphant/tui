<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use InvalidArgumentException;
use Stringable;

final readonly class KeyPattern implements Stringable
{
    private const NAMED_KEYS = [
        'enter' => Key::Enter, 'return' => Key::Enter,
        'esc' => Key::Escape, 'escape' => Key::Escape,
        'tab' => Key::Tab,
        'backspace' => Key::Backspace, 'bs' => Key::Backspace,
        'delete' => Key::Delete, 'del' => Key::Delete,
        'insert' => Key::Insert, 'ins' => Key::Insert,
        'up' => Key::Up, 'down' => Key::Down,
        'left' => Key::Left, 'right' => Key::Right,
        'home' => Key::Home, 'end' => Key::End,
        'page_up' => Key::PageUp, 'pgup' => Key::PageUp, 'pageup' => Key::PageUp,
        'page_down' => Key::PageDown, 'pgdn' => Key::PageDown, 'pagedown' => Key::PageDown,
        'f1' => Key::F1, 'f2' => Key::F2, 'f3' => Key::F3, 'f4' => Key::F4,
        'f5' => Key::F5, 'f6' => Key::F6, 'f7' => Key::F7, 'f8' => Key::F8,
        'f9' => Key::F9, 'f10' => Key::F10, 'f11' => Key::F11, 'f12' => Key::F12,
        'space' => ' ',
    ];

    public function __construct(
        public Key|string $key,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
    ) {
    }

    /**
     * What a binding would call this key. One spelling for both directions, so a key
     * read back from a trace can be compared with the pattern that was meant to match
     * it.
     */
    public static function nameOf(Key|string $key): string
    {
        $name = array_search($key, self::NAMED_KEYS, true);

        if ($name !== false) {
            return $name;
        }

        return $key instanceof Key ? strtolower($key->name) : $key;
    }

    public static function fromString(string $pattern): self
    {
        if ($pattern === '') {
            throw new InvalidArgumentException('Empty pattern');
        }

        $rawTokens = match (true) {
            $pattern === '+' => ['+'],
            str_ends_with($pattern, '++') => [...explode('+', substr($pattern, 0, -2)), '+'],
            default => explode('+', $pattern),
        };
        $tokens = array_map(
            static fn (string $token): string => strtolower(trim($token)),
            $rawTokens,
        );
        $count = count($tokens);

        $modifiers = ['ctrl', 'alt', 'shift'];

        if ($count === 1 && in_array($tokens[0], $modifiers, true)) {
            throw new InvalidArgumentException("Pattern '{$pattern}' has no key");
        }

        $ctrl = $alt = $shift = false;
        $seen = [];

        for ($i = 0; $i < $count - 1; ++$i) {
            $token = $tokens[$i];

            if ($token === '') {
                throw new InvalidArgumentException("Empty modifier in pattern '{$pattern}'");
            }

            if (isset($seen[$token])) {
                throw new InvalidArgumentException("Duplicate modifier '{$token}' in pattern '{$pattern}'");
            }
            $seen[$token] = true;

            match ($token) {
                'ctrl' => $ctrl = true,
                'alt' => $alt = true,
                'shift' => $shift = true,
                default => throw new InvalidArgumentException("Unknown modifier '{$token}' in pattern '{$pattern}'"),
            };
        }

        $keyToken = $tokens[$count - 1];

        if ($keyToken === '') {
            throw new InvalidArgumentException("Empty key in pattern '{$pattern}'");
        }

        if (isset(self::NAMED_KEYS[$keyToken])) {
            $key = self::NAMED_KEYS[$keyToken];
        } elseif (strlen($keyToken) === 1) {
            $key = $keyToken;
        } elseif (ctype_alpha($keyToken) && strlen($keyToken) >= 4) {
            throw new InvalidArgumentException("Unknown key '{$keyToken}' in pattern '{$pattern}'");
        } else {
            throw new InvalidArgumentException("Multi-character key '{$keyToken}' is not a named key in pattern '{$pattern}'");
        }

        // Shifted letter normalization: bare uppercase single letter A-Z in the
        // ORIGINAL input (no modifier yet set) implies shift.
        $originalKeyToken = trim($rawTokens[$count - 1]);
        if (!$ctrl && !$alt && !$shift
            && strlen($originalKeyToken) === 1
            && $originalKeyToken >= 'A' && $originalKeyToken <= 'Z'
        ) {
            $shift = true;
        }

        return new self(key: $key, ctrl: $ctrl, alt: $alt, shift: $shift);
    }

    public function __toString(): string
    {
        $parts = [];
        if ($this->ctrl) {
            $parts[] = 'ctrl';
        }
        if ($this->alt) {
            $parts[] = 'alt';
        }
        if ($this->shift) {
            $parts[] = 'shift';
        }

        $keyStr = $this->key instanceof Key
            ? strtolower($this->key->name)
            : $this->key;

        $parts[] = $keyStr;

        return implode('+', $parts);
    }

    /**
     * Human-readable display form for key hints, e.g. "Ctrl+Q", "↑", "Esc".
     */
    public function label(): string
    {
        $parts = [];
        if ($this->ctrl) {
            $parts[] = 'Ctrl';
        }
        if ($this->alt) {
            $parts[] = 'Alt';
        }
        if ($this->shift) {
            $parts[] = 'Shift';
        }

        $parts[] = $this->keyLabel($parts !== []);

        return implode('+', $parts);
    }

    /**
     * One label for keys that do the same job, writing a modifier they all share
     * once: "Shift+←" and "Shift+→" become "Shift+←/→".
     */
    public static function joinedLabel(self $first, self ...$rest): string
    {
        $labels = [$first->label()];
        foreach ($rest as $pattern) {
            $labels[] = $pattern->label();
        }

        $lastModifier = strrpos($labels[0], '+');
        $modifiers = $lastModifier === false ? '' : substr($labels[0], 0, $lastModifier + 1);

        if ($modifiers === '') {
            return self::withOptionalModifier($labels) ?? implode('/', $labels);
        }

        $tails = [];
        foreach ($labels as $label) {
            if (!str_starts_with($label, $modifiers)) {
                return implode('/', $labels);
            }
            $tails[] = substr($label, \strlen($modifiers));
        }

        return $modifiers . implode('/', $tails);
    }

    public static function fromKeyMsg(KeyMsg $msg): self
    {
        $key = $msg->key;
        $shift = $msg->shift;

        if (is_string($key) && strlen($key) === 1
            && $key >= 'A' && $key <= 'Z'
        ) {
            $key = strtolower($key);
            $shift = true;
        }

        return new self(key: $key, ctrl: $msg->ctrl, alt: $msg->alt, shift: $shift);
    }

    public function matches(KeyMsg $msg): bool
    {
        // Repeat events fire while a key is held; they must keep triggering
        // actions like list navigation.
        if ($msg->isRelease()) {
            return false;
        }

        $key = $msg->key;
        $shift = $msg->shift;

        if (is_string($key) && strlen($key) === 1
            && $key >= 'A' && $key <= 'Z'
        ) {
            $key = strtolower($key);
            $shift = true;
        }

        return $this->key === $key
            && $this->ctrl === $msg->ctrl
            && $this->alt === $msg->alt
            && $this->shift === $shift;
    }

    /**
     * "↑", "↓", "Shift+↑", "Shift+↓" is the same pair twice, so it reads as
     * "(Shift+)↑/↓".
     *
     * @param list<string> $labels
     */
    private static function withOptionalModifier(array $labels): ?string
    {
        $plain = [];
        $modified = [];
        foreach ($labels as $label) {
            $lastModifier = strrpos($label, '+');
            if ($lastModifier === false) {
                $plain[] = $label;
                continue;
            }
            $modified[substr($label, 0, $lastModifier + 1)][] = substr($label, $lastModifier + 1);
        }

        if ($plain === [] || count($modified) !== 1) {
            return null;
        }

        $modifiers = array_key_first($modified);

        return $modified[$modifiers] === $plain
            ? '(' . $modifiers . ')' . implode('/', $plain)
            : null;
    }

    private function keyLabel(bool $hasModifiers): string
    {
        if ($this->key instanceof Key) {
            return match ($this->key) {
                Key::Up => '↑',
                Key::Down => '↓',
                Key::Left => '←',
                Key::Right => '→',
                Key::Escape => 'Esc',
                Key::PageUp => 'PgUp',
                Key::PageDown => 'PgDn',
                default => $this->key->name,
            };
        }

        if ($this->key === ' ') {
            return 'Space';
        }

        return $hasModifiers ? strtoupper($this->key) : $this->key;
    }
}
