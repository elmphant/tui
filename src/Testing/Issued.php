<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;

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
        return match (true) {
            $command === null => [],
            $command instanceof MappedCmd, $command instanceof ScopedCmd => self::in($command->inner, $type),
            $command instanceof BatchCmd => array_merge(...array_map(
                static fn (Cmd $child): array => self::in($child, $type),
                $command->commands,
            )),
            $command instanceof $type => [$command],
            default => [],
        };
    }
}
