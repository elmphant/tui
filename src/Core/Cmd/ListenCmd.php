<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\Message;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class ListenCmd extends Cmd
{
    /**
     * @param resource                             $stream
     * @param Closure(resource): iterable<Message> $onReadable
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
