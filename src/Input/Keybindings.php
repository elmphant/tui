<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\KeyMsg;
use InvalidArgumentException;

final readonly class Keybindings
{
    /**
     * @var array<string, list<KeyPattern>>
     */
    private array $patterns;

    /**
     * @param array<string, list<string|KeyPattern>> $bindings
     */
    public function __construct(array $bindings = [])
    {
        $parsed = [];
        foreach ($bindings as $action => $patternList) {
            $parsed[$action] = [];
            foreach ($patternList as $pattern) {
                if ($pattern instanceof KeyPattern) {
                    $parsed[$action][] = $pattern;
                    continue;
                }

                try {
                    $parsed[$action][] = KeyPattern::fromString($pattern);
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidArgumentException("Invalid pattern for action '{$action}': " . $exception->getMessage(), previous: $exception);
                }
            }
        }
        $this->patterns = $parsed;
    }

    public static function empty(): self
    {
        static $instance = null;

        return $instance ??= new self([]);
    }

    /**
     * Moving a cursor through rows and choosing one, as a list or a table does.
     */
    public static function rowNavigation(): self
    {
        return new self([
            Actions::CURSOR_DOWN => ['down'],
            Actions::CURSOR_UP => ['up'],
            Actions::HOME => ['home'],
            Actions::END => ['end'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::SELECT => ['enter', 'space'],
        ]);
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * @return list<KeyPattern>
     */
    public function get(string $action): array
    {
        return $this->patterns[$action] ?? [];
    }

    public function matches(KeyMsg $msg, string $action): bool
    {
        foreach ($this->patterns[$action] ?? [] as $pattern) {
            if ($pattern->matches($msg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a key-hint map (display label => description) from these bindings,
     * so footers and status bars stay in sync with what actually matches.
     *
     * A description covering several actions gets one label for all of them, from
     * the key each action leads with. Actions without a binding are skipped,
     * descriptions left with none are dropped, and the order given is kept.
     *
     * @param array<string, string|list<string>> $descriptions description => the action(s) it covers
     *
     * @return array<string, string> key label => description
     */
    public function hints(array $descriptions): array
    {
        $hints = [];
        foreach ($descriptions as $description => $actions) {
            $patterns = [];
            foreach (\is_string($actions) ? [$actions] : $actions as $action) {
                $pattern = $this->patterns[$action][0] ?? null;
                if ($pattern !== null) {
                    $patterns[] = $pattern;
                }
            }

            if ($patterns !== []) {
                $hints[KeyPattern::joinedLabel(...$patterns)] = $description;
            }
        }

        return $hints;
    }

    public function merge(self $other): self
    {
        $copy = $this->patterns;
        foreach ($other->patterns as $action => $patterns) {
            $copy[$action] = $patterns;
        }

        return new self($copy);
    }

    public function append(self $other): self
    {
        $copy = $this->patterns;
        foreach ($other->patterns as $action => $newPatterns) {
            $existing = $copy[$action] ?? [];
            foreach ($newPatterns as $pattern) {
                if (!in_array((string)$pattern, array_map(strval(...), $existing), true)) {
                    $existing[] = $pattern;
                }
            }
            $copy[$action] = $existing;
        }

        return new self($copy);
    }
}
