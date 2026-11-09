<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\CmdLeaf;
use Elmphant\Tui\Core\EffectId;
use LogicException;

/**
 * @internal
 */
final class WantedTicks
{
    private const NAMED_TICKS_ONLY = 'wantedTicks() answers named ticks only';

    /**
     * @var array<string, CmdLeaf>
     */
    private array $running = [];

    /**
     * @return list<CmdLeaf>
     */
    public function follow(?Cmd $wanted): array
    {
        $changes = [];
        $wantedNow = [];
        foreach (CmdLeaf::all($wanted) as $leaf) {
            $key = self::key($leaf);
            if (!isset($this->running[$key]) || self::tickOf($this->running[$key])->interval !== self::tickOf($leaf)->interval) {
                $changes[] = $leaf;
            }
            $wantedNow[$key] = $leaf;
        }

        foreach (array_diff_key($this->running, $wantedNow) as $gone) {
            $changes[] = CmdLeaf::all(Cmd::cancel(self::idOf($gone)), $gone->wrap, $gone->scope)[0];
        }
        $this->running = $wantedNow;

        return $changes;
    }

    private static function key(CmdLeaf $leaf): string
    {
        $id = self::idOf($leaf);

        return (string)($leaf->scope === null ? $id : $id->within($leaf->scope));
    }

    private static function idOf(CmdLeaf $leaf): EffectId
    {
        return self::tickOf($leaf)->id ?? throw new LogicException(self::NAMED_TICKS_ONLY);
    }

    private static function tickOf(CmdLeaf $leaf): TickCmd
    {
        return $leaf->command instanceof TickCmd ? $leaf->command : throw new LogicException(self::NAMED_TICKS_ONLY);
    }
}
