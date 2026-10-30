<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\KeyMsg;

/**
 * Mutable input state owned by the runtime between keystrokes, never by a model.
 *
 * @internal the runtime feeds it; applications bind chords as "g g" in Keybindings
 */
final class ChordTracker
{
    /**
     * @var list<KeyMsg>
     */
    private array $held = [];

    private ?float $deadline = null;

    public function __construct(private readonly float $timeout = 0.5)
    {
    }

    /**
     * Return no keys while waiting, one completed chord, or the buffered keys followed by the key that broke the chord.
     *
     * @return list<KeyMsg>
     */
    public function feed(KeyMsg $key, Keybindings $chords, float $now): array
    {
        $completed = $key->withPreceding($this->held);

        if ($this->held !== [] && $chords->actionOf($completed) !== null) {
            $this->reset();

            return [$completed];
        }

        if ($this->held !== [] && KeyPattern::fromString('esc')->matches($key)) {
            $this->reset();

            return [];
        }

        if ($chords->continues(...[...$this->held, $key])) {
            $this->held[] = $key;
            $this->deadline = $now + $this->timeout;

            return [];
        }

        if ($this->held === []) {
            return [$key];
        }

        $flushed = $this->held;
        $this->reset();

        return [...$flushed, ...$this->feed($key, $chords, $now)];
    }

    /**
     * @return list<KeyMsg>
     */
    public function expired(float $now): array
    {
        if ($this->deadline === null || $now < $this->deadline) {
            return [];
        }

        $flushed = $this->held;
        $this->reset();

        return $flushed;
    }

    /**
     * @return list<KeyMsg>
     */
    public function held(): array
    {
        return $this->held;
    }

    public function deadline(): ?float
    {
        return $this->deadline;
    }

    private function reset(): void
    {
        $this->held = [];
        $this->deadline = null;
    }
}
