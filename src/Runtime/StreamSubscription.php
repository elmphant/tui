<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

/**
 * @internal
 */
final class StreamSubscription
{
    public bool $readable = false;

    public ?string $watcherId = null;

    /**
     * @param resource|closed-resource            $stream     a reader may close it, and often does on finding its end
     * @param Closure(resource):iterable<Message> $onReadable
     * @param Closure(Message):Message|null       $wrap
     */
    public function __construct(
        public readonly mixed $stream,
        public readonly Closure $onReadable,
        public readonly ?EffectId $id = null,
        public readonly ?Closure $wrap = null,
        public readonly ?string $scope = null,
    ) {
    }

    /**
     * @return iterable<Message>
     */
    public function read(): iterable
    {
        return ($this->onReadable)($this->stream);
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }
}
