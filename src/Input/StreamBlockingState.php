<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

/**
 * @internal exact inherited blocking state for a terminal input stream
 */
final class StreamBlockingState
{
    private bool $restored = false;

    /**
     * @param resource $stream
     */
    private function __construct(
        private readonly mixed $stream,
        private readonly bool $originallyBlocking,
    ) {
    }

    /**
     * @param resource $stream
     */
    public static function capture(mixed $stream): self
    {
        $metadata = stream_get_meta_data($stream);

        return new self($stream, $metadata['blocked']);
    }

    public function makeNonBlocking(): bool
    {
        return stream_set_blocking($this->stream, false);
    }

    public function restore(): bool
    {
        if ($this->restored) {
            return true;
        }

        if (!stream_set_blocking($this->stream, $this->originallyBlocking)) {
            return false;
        }

        $this->restored = true;

        return true;
    }
}
