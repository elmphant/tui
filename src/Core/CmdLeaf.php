<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;

/**
 * @internal one command inside the maps, scopes and batches around it
 */
final readonly class CmdLeaf
{
    /**
     * @param (Closure(Message): Message)|null $wrap
     */
    private function __construct(
        public Cmd $command,
        public ?Closure $wrap,
        public ?string $scope,
    ) {
    }

    /**
     * @param (Closure(Message): Message)|null $wrap
     *
     * @return list<self>
     */
    public static function all(?Cmd $command, ?Closure $wrap = null, ?string $scope = null): array
    {
        return match (true) {
            $command === null => [],
            $command instanceof MappedCmd => self::all($command->inner, self::outside($command->wrap, $wrap), $scope),
            $command instanceof ScopedCmd => self::all($command->inner, $wrap, $scope === null ? $command->scope : $scope . '/' . $command->scope),
            $command instanceof BatchCmd => array_merge(...array_map(
                static fn (Cmd $child): array => self::all($child, $wrap, $scope),
                $command->commands,
            )),
            default => [new self($command, $wrap, $scope)],
        };
    }

    /**
     * @param Closure(Message): Message        $inner
     * @param (Closure(Message): Message)|null $outer
     *
     * @return Closure(Message): Message
     */
    private static function outside(Closure $inner, ?Closure $outer): Closure
    {
        return $outer === null ? $inner : static fn (Message $message): Message => $outer($inner($message));
    }
}
