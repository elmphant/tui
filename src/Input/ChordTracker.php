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

    /**
     * The keys to handle now, in order: none while a chord is still open, the
     * completed chord as one key, or the held keys followed by a key that broke them off.
     *
     * @return list<KeyMsg>
     */
    public function feed(KeyMsg $key, Keybindings $chords): array
    {
        $completed = $key->withPreceding($this->held);

        if ($this->held !== [] && $chords->actionOf($completed) !== null) {
            $this->held = [];

            return [$completed];
        }

        if ($chords->continues(...[...$this->held, $key])) {
            $this->held[] = $key;

            return [];
        }

        if ($this->held === []) {
            return [$key];
        }

        $flushed = $this->held;
        $this->held = [];

        return [...$flushed, ...$this->feed($key, $chords)];
    }
}
