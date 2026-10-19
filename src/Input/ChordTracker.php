<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\KeyMsg;

/**
 * The keys of a chord typed so far. Mutable on purpose: it is input state the
 * runtime holds between keystrokes, like a process handle, never part of a model.
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
     * The keys to handle now, in order: none while a chord is still open, the
     * completed chord as one key, or the held keys followed by a key that broke them off.
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
     * The held keys, once nothing followed them in time; they are handled on their own.
     *
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
