<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Symfony\Component\Process\Process;

/**
 * Symfony stops only the process it started, so a shell's or a script's own children would outlive a cancel.
 *
 * @internal
 */
final class ProcessTree
{
    private const KILL = 9;

    /**
     * Find descendants before the process exits; afterwards they are reparented and cannot be found through it.
     *
     * @return list<int>
     */
    public static function descendantsOf(int $pid): array
    {
        $listing = new Process(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
        $listing->run();

        $childrenOf = [];
        foreach (explode("\n", $listing->getOutput()) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if ($fields !== false && count($fields) === 2) {
                $childrenOf[(int)$fields[1]][] = (int)$fields[0];
            }
        }

        $descendants = [];
        $waiting = $childrenOf[$pid] ?? [];
        while ($waiting !== []) {
            $child = array_shift($waiting);
            $descendants[] = $child;
            $waiting = [...$waiting, ...($childrenOf[$child] ?? [])];
        }

        return $descendants;
    }

    /**
     * @param list<int> $pids
     */
    public static function kill(array $pids): void
    {
        self::signal($pids, self::KILL);
    }

    /**
     * @param list<int> $pids
     */
    private static function signal(array $pids, int $signal): void
    {
        if ($pids === []) {
            return;
        }
        if (\function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @posix_kill($pid, $signal);
            }

            return;
        }

        (new Process(['kill', '-' . $signal, ...array_map('strval', $pids)]))->run();
    }
}
