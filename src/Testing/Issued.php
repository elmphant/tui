<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\CmdLeaf;

final class Issued
{
    /**
     * @template T of Cmd
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public static function in(?Cmd $command, string $type = Cmd::class): array
    {
        $found = [];
        foreach (CmdLeaf::all($command) as $leaf) {
            if ($leaf->command instanceof $type) {
                $found[] = $leaf->command;
            }
        }

        return $found;
    }
}
