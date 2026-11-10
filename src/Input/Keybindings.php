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
    public function patternsOf(string $action): array
    {
        return $this->patterns[$action] ?? [];
    }

    public function matches(KeyMsg $message, string $action): bool
    {
        foreach ($this->patterns[$action] ?? [] as $pattern) {
            if ($pattern->matches($message)) {
                return true;
            }
        }

        return false;
    }

    public function actionOf(KeyMsg $message): ?string
    {
        foreach ($this->actions() as $action) {
            if ($this->matches($message, $action)) {
                return $action;
            }
        }

        return null;
    }

    public function restrictedTo(string ...$actions): self
    {
        return new self(array_intersect_key($this->patterns, array_flip($actions)));
    }

    public function chords(): self
    {
        return $this->filtered(static fn (KeyPattern $pattern): bool => $pattern->preceding !== []);
    }

    /**
     * What each key that can follow $typed does, for a reader halfway through a chord.
     *
     * @return array<string, string> key label => description
     */
    public function continuations(KeyMsg ...$typed): array
    {
        $continuations = [];
        foreach ($this->patterns as $action => $patterns) {
            foreach ($patterns as $pattern) {
                $next = $pattern->stepAfter(...$typed);
                if ($next !== null) {
                    $continuations[$next->label()] ??= Actions::description($action);
                }
            }
        }

        return $continuations;
    }

    public function continues(KeyMsg ...$typed): bool
    {
        foreach ($this->patterns as $patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern->stepAfter(...$typed) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Derive hints from bindings: merge actions with the same description, skip unbound actions and preserve order.
     *
     * @param array<string, string|list<string>|Gesture> $descriptions description => the action(s) or gesture it covers
     *
     * @return array<string, string> key label => description
     */
    public function hints(array $descriptions): array
    {
        return array_column($this->keyHints($descriptions), 'description', 'label');
    }

    /**
     * Each hint with the key it leads with.
     *
     * @param array<string, string|list<string>|Gesture> $descriptions description => the action(s) or gesture it covers
     *
     * @return list<KeyHint>
     */
    public function keyHints(array $descriptions): array
    {
        $hints = [];
        foreach ($descriptions as $description => $actions) {
            if ($actions instanceof Gesture) {
                $hints[] = new KeyHint($actions->label(), $description);
                continue;
            }

            $patterns = [];
            foreach (\is_string($actions) ? [$actions] : $actions as $action) {
                $pattern = $this->patterns[$action][0] ?? null;
                if ($pattern !== null) {
                    $patterns[] = $pattern;
                }
            }

            if ($patterns !== []) {
                $hints[] = new KeyHint(KeyPattern::joinedLabel(...$patterns), $description, $patterns[0]);
            }
        }

        return $hints;
    }

    /**
     * Return null if any action is unbound, so hints cannot promise a shortcut that does not exist.
     */
    public function label(string ...$actions): ?string
    {
        $patterns = [];
        foreach ($actions as $action) {
            $pattern = $this->patterns[$action][0] ?? null;
            if ($pattern === null) {
                return null;
            }
            $patterns[] = $pattern;
        }

        return $patterns === [] ? null : KeyPattern::joinedLabel(...$patterns);
    }

    public function withoutText(): self
    {
        return $this->filtered(static function (KeyPattern $pattern): bool {
            $first = $pattern->preceding[0] ?? $pattern;

            return !is_string($first->key) || $first->ctrl || $first->alt;
        });
    }

    public function except(self $other): self
    {
        $taken = [];
        foreach ($other->patterns as $patterns) {
            foreach ($patterns as $pattern) {
                $taken[(string)$pattern] = true;
            }
        }

        return $this->filtered(static fn (KeyPattern $pattern): bool => !isset($taken[(string)$pattern]));
    }

    public function overriddenBy(self $other): self
    {
        $copy = $this->patterns;
        foreach ($other->patterns as $action => $patterns) {
            $copy[$action] = $patterns;
        }

        return new self($copy);
    }

    public function plus(self $other): self
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

    /**
     * @param callable(KeyPattern): bool $keep
     */
    private function filtered(callable $keep): self
    {
        return new self(bindings: array_map(
            static fn (array $patterns): array => array_values(array_filter($patterns, $keep)),
            $this->patterns,
        ));
    }
}
