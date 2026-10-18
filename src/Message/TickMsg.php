<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

final readonly class TickMsg implements Message
{
    use ComesFromAnEffect;

    public function __construct(public float $timestamp, public ?EffectId $effectId = null)
    {
    }
}
