<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Text\Grapheme;
use InvalidArgumentException;
use Stringable;

final readonly class KeyPattern implements Stringable
{
    private const NAMED_KEYS = [...Key::NAMES, 'space' => ' '];

    /**
     * Letters joined in one label read left to right, as they sit: "h/l", "j/k".
     */
    private const KEYBOARD_ORDER = 'qwertyuiopasdfghjklzxcvbnm';

    /**
     * @param list<self> $preceding the keys of a chord typed before this one
     */
    public function __construct(
        public Key|string $key,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
        public array $preceding = [],
    ) {
    }

    /**
     * Use the same spelling in bindings and traces so keys can be compared.
     */
    public static function nameOf(Key|string $key): string
    {
        $name = array_search($key, self::NAMED_KEYS, true);

        if ($name !== false) {
            return $name;
        }

        return $key instanceof Key ? strtolower($key->name) : $key;
    }

    /**
     * One key ("ctrl+s"), or a chord of keys typed one after the other, separated
     * by spaces ("g g", "ctrl+x ctrl+s").
     */
    public static function fromString(string $pattern): self
    {
        $steps = preg_split('/(?<!\+)\s+(?!\+)/', trim($pattern));

        if ($steps === false || count($steps) < 2) {
            return self::fromKeyString($pattern);
        }

        $keys = array_map(self::fromKeyString(...), $steps);
        $last = array_pop($keys);

        return new self($last->key, $last->ctrl, $last->alt, $last->shift, $keys);
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

        return implode(' ', [...array_map(strval(...), $this->preceding), implode('+', $parts)]);
    }

    /**
     * Human-readable display form for key hints, e.g. "Ctrl+Q", "↑", "Esc", "G";
     * a chord as its keys are typed, "gg".
     */
    public function label(): string
    {
        if ($this->preceding !== []) {
            $labels = array_map(static fn (self $step): string => $step->label(), $this->steps());
            $single = array_filter($labels, static fn (string $label): bool => mb_strlen($label) === 1) === $labels;

            return implode($single ? '' : ' ', $labels);
        }

        if ($this->shift && !$this->ctrl && !$this->alt && is_string($this->key) && ctype_lower($this->key)) {
            return strtoupper($this->key);
        }

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

        if (array_filter($labels, static fn (string $label): bool => strlen($label) === 1 && ctype_alpha($label)) === $labels) {
            usort($labels, static fn (string $left, string $right): int => stripos(self::KEYBOARD_ORDER, $left) <=> stripos(self::KEYBOARD_ORDER, $right));

            return implode('/', $labels);
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

    public static function fromKeyMsg(KeyMsg $message): self
    {
        $key = $message->key;
        $shift = $message->shift;

        if (is_string($key) && strlen($key) === 1
            && $key >= 'A' && $key <= 'Z'
        ) {
            $key = strtolower($key);
            $shift = true;
        }

        return new self(key: $key, ctrl: $message->ctrl, alt: $message->alt, shift: $shift, preceding: array_map(self::fromKeyMsg(...), $message->preceding));
    }

    public function pressed(): KeyMsg
    {
        $preceding = array_map(static fn (self $step): KeyMsg => $step->pressed(), $this->preceding);

        if ($this->shift && !$this->ctrl && !$this->alt && \is_string($this->key) && strlen($this->key) === 1 && ctype_lower($this->key)) {
            return new KeyMsg(strtoupper($this->key), preceding: $preceding);
        }

        return new KeyMsg($this->key, $this->ctrl, $this->alt, $this->shift, preceding: $preceding);
    }

    public function matches(KeyMsg $message): bool
    {
        // Handle repeat events so holding a key continues actions such as list navigation.
        if ($message->isRelease()) {
            return false;
        }

        $key = $message->key;
        $shift = $message->shift;

        if (is_string($key) && strlen($key) === 1
            && $key >= 'A' && $key <= 'Z'
        ) {
            $key = strtolower($key);
            $shift = true;
        }

        if (count($this->preceding) !== count($message->preceding)) {
            return false;
        }

        foreach ($this->preceding as $index => $step) {
            if (!$step->matches($message->preceding[$index])) {
                return false;
            }
        }

        return $this->key === $key
            && $this->ctrl === $message->ctrl
            && $this->alt === $message->alt
            && $this->shift === $shift;
    }

    /**
     * The key that goes on from $typed when this chord begins with them and is
     * longer; null otherwise.
     */
    public function stepAfter(KeyMsg ...$typed): ?self
    {
        $steps = $this->steps();

        foreach (array_values($typed) as $index => $key) {
            if (!isset($steps[$index]) || !$steps[$index]->matches($key)) {
                return null;
            }
        }

        return $steps[count($typed)] ?? null;
    }

    /**
     * @return non-empty-list<self>
     */
    private function steps(): array
    {
        return [...$this->preceding, new self($this->key, $this->ctrl, $this->alt, $this->shift)];
    }

    private static function fromKeyString(string $pattern): self
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
                default => throw new InvalidArgumentException("Unknown modifier '{$token}' in pattern '{$pattern}'" . self::nearest($token, ['ctrl', 'alt', 'shift'])),
            };
        }

        $keyToken = $tokens[$count - 1];

        if ($keyToken === '') {
            throw new InvalidArgumentException("Empty key in pattern '{$pattern}'");
        }

        if (isset(self::NAMED_KEYS[$keyToken])) {
            $key = self::NAMED_KEYS[$keyToken];
        } elseif (count(Grapheme::split($keyToken)) === 1) {
            $key = $keyToken;
        } elseif (ctype_alpha($keyToken) && strlen($keyToken) >= 4) {
            throw new InvalidArgumentException("Unknown key '{$keyToken}' in pattern '{$pattern}'" . self::nearest($keyToken, array_keys(self::NAMED_KEYS)));
        } else {
            throw new InvalidArgumentException("Multi-character key '{$keyToken}' is not a named key in pattern '{$pattern}'" . self::nearest($keyToken, array_keys(self::NAMED_KEYS)));
        }

        // A bare uppercase ASCII letter implies Shift only before modifiers are parsed.
        $originalKeyToken = trim($rawTokens[$count - 1]);
        if (!$ctrl && !$alt && !$shift
            && strlen($originalKeyToken) === 1
            && $originalKeyToken >= 'A' && $originalKeyToken <= 'Z'
        ) {
            $shift = true;
        }

        return new self(key: $key, ctrl: $ctrl, alt: $alt, shift: $shift);
    }

    /**
     * @param list<string> $names
     */
    private static function nearest(string $token, array $names): string
    {
        $allowed = strlen($token) >= 6 ? 2 : 1;
        $distances = array_combine($names, array_map(static fn (string $name): int => levenshtein($token, $name), $names));
        asort($distances);
        $name = (string)array_key_first($distances);

        return $distances[$name] <= $allowed ? ". Did you mean '{$name}'?" : '';
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
