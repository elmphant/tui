<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

/**
 * @internal the rows Cmd::flash() lit, held by the runtime like a toast, so no model stores a time
 */
final class Flashes
{
    /**
     * @var array<string, array<string, array{float, float}>> component => row key => [start, seconds]
     */
    private array $lit = [];

    public function show(string $component, string $key, float $seconds, float $now): void
    {
        $this->lit[$component][$key] = [$now, max(0.1, $seconds)];
    }

    /**
     * From 1.0 the moment the row was lit down to 0.0 once its seconds are over.
     */
    public function strengthOf(string $component, string $key, float $now): float
    {
        [$start, $seconds] = $this->lit[$component][$key] ?? [0.0, 0.0];

        return $seconds === 0.0 ? 0.0 : max(0.0, 1.0 - ($now - $start) / $seconds);
    }

    /**
     * @return bool whether any flash ended
     */
    public function expire(float $now): bool
    {
        $ended = false;

        foreach ($this->lit as $component => $keys) {
            foreach ($keys as $key => [$start, $seconds]) {
                if ($now >= $start + $seconds) {
                    unset($this->lit[$component][$key]);
                    $ended = true;
                }
            }

            if ($this->lit[$component] === []) {
                unset($this->lit[$component]);
            }
        }

        return $ended;
    }

    public function nextDeadline(): ?float
    {
        $deadlines = [];

        foreach ($this->lit as $keys) {
            foreach ($keys as [$start, $seconds]) {
                $deadlines[] = $start + $seconds;
            }
        }

        return $deadlines === [] ? null : min($deadlines);
    }
}
