<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;

final readonly class PlaySoundCmd extends Cmd implements NamesItsEffect
{
    public function __construct(private ?EffectId $id = null)
    {
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }
}
