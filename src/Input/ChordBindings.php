<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use InvalidArgumentException;

final readonly class ChordBindings
{
    /**
     * @var array<string, list<list<KeyPattern>>>
     */
    private array $sequences;

    /**
     * @param array<string, list<list<string|KeyPattern>>> $bindings
     */
    public function __construct(array $bindings = [])
    {
        $parsed = [];
        foreach ($bindings as $action => $sequenceList) {
            if ($sequenceList === []) {
                throw new InvalidArgumentException("Action '{$action}' has no sequences");
            }

            $parsedSequences = [];
            foreach ($sequenceList as $sequence) {
                if ($sequence === []) {
                    throw new InvalidArgumentException("Empty sequence for action '{$action}'");
                }

                $parsedSteps = [];
                foreach ($sequence as $step) {
                    if ($step instanceof KeyPattern) {
                        $parsedSteps[] = $step;
                        continue;
                    }
                    try {
                        $parsedSteps[] = KeyPattern::fromString($step);
                    } catch (InvalidArgumentException $exception) {
                        throw new InvalidArgumentException("Invalid chord step for action '{$action}': " . $exception->getMessage(), previous: $exception);
                    }
                }
                $parsedSequences[] = $parsedSteps;
            }
            $parsed[$action] = $parsedSequences;
        }
        $this->sequences = $parsed;
    }

    public static function empty(): self
    {
        static $instance = null;

        return $instance ??= new self();
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_keys($this->sequences);
    }

    /**
     * @return list<list<KeyPattern>>
     */
    public function sequencesFor(string $action): array
    {
        return $this->sequences[$action] ?? [];
    }

    /**
     * @return list<array{action: string, pattern: list<KeyPattern>}>
     */
    public function allSequences(): array
    {
        $result = [];
        foreach ($this->sequences as $action => $sequenceList) {
            foreach ($sequenceList as $sequence) {
                $result[] = ['action' => $action, 'pattern' => $sequence];
            }
        }

        return $result;
    }
}
