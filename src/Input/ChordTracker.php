<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use LogicException;

/**
 * Stateful multi-key sequence tracker.
 *
 * This class is intentionally MUTABLE — it represents external input state,
 * same category as a process handle or HTTP stream, and does NOT belong in
 * the immutable TEA model. Apps hold a single tracker instance for their
 * lifetime and pass the same reference across state transitions; do not
 * clone via `with()`.
 */
/**
 * @internal stateful runtime matcher; applications configure immutable ChordBindings instead
 */
final class ChordTracker
{
    /**
     * @var list<KeyPattern>
     */
    private array $buffer = [];

    private ?float $lastKeyTime = null;

    public function __construct(
        private readonly ChordBindings $bindings,
        private readonly int $timeoutMs = 500,
    ) {
    }

    public function feed(KeyMsg $msg, float $now): string|KeyMsg|null
    {
        if ($msg->eventType !== KeyEventType::Press) {
            return $msg;
        }

        if ($this->buffer !== [] && $this->lastKeyTime !== null
            && ($now - $this->lastKeyTime) * 1000.0 > $this->timeoutMs
        ) {
            $this->buffer = [];
            $this->lastKeyTime = null;
        }

        $candidate = [...$this->buffer, KeyPattern::fromKeyMsg($msg)];
        $match = $this->classify($candidate);

        if ($match === ChordMatch::Complete) {
            $action = $this->actionFor($candidate);
            $this->buffer = [];
            $this->lastKeyTime = null;

            return $action;
        }

        if ($match === ChordMatch::Prefix) {
            $this->buffer = $candidate;
            $this->lastKeyTime = $now;

            return null;
        }

        $this->buffer = [];
        $this->lastKeyTime = null;

        return $msg;
    }

    /**
     * @return list<KeyPattern>
     */
    public function buffer(): array
    {
        return $this->buffer;
    }

    public function reset(): void
    {
        $this->buffer = [];
        $this->lastKeyTime = null;
    }

    /**
     * @param list<KeyPattern> $candidate
     */
    private function classify(array $candidate): ChordMatch
    {
        $isPrefix = false;
        foreach ($this->bindings->allSequences() as ['pattern' => $seq]) {
            if ($this->sequencesEqual($candidate, $seq)) {
                return ChordMatch::Complete;
            }
            if (count($candidate) < count($seq) && $this->isPrefixOf($candidate, $seq)) {
                $isPrefix = true;
            }
        }

        return $isPrefix ? ChordMatch::Prefix : ChordMatch::None;
    }

    /**
     * @param list<KeyPattern> $candidate
     */
    private function actionFor(array $candidate): string
    {
        foreach ($this->bindings->allSequences() as $entry) {
            if ($this->sequencesEqual($candidate, $entry['pattern'])) {
                return $entry['action'];
            }
        }
        throw new LogicException('actionFor called without a complete match');
    }

    /**
     * @param list<KeyPattern> $a
     * @param list<KeyPattern> $b
     */
    private function sequencesEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $index => $step) {
            if ((string)$step !== (string)$b[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<KeyPattern> $prefix
     * @param list<KeyPattern> $full
     */
    private function isPrefixOf(array $prefix, array $full): bool
    {
        foreach ($prefix as $index => $step) {
            if (!isset($full[$index]) || (string)$step !== (string)$full[$index]) {
                return false;
            }
        }

        return true;
    }
}
