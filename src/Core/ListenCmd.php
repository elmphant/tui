<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class ListenCmd extends Cmd
{
    /**
     * @param resource                                                   $stream
     * @param Closure(resource): iterable<\Elmphant\Tui\Message\Message> $onReadable
     */
    public function __construct(
        public mixed $stream,
        public Closure $onReadable,
        public ?EffectId $id = null,
    ) {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Cmd::listen() needs an open stream resource');
        }
    }
}
