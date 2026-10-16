<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Terminal;

use Elmphant\Tui\Terminal\StreamBlockingState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamBlockingStateTest extends TestCase
{
    #[Test]
    public function restoresAnOriginallyBlockingStream(): void
    {
        [$stream, $peer] = $this->socketPair();
        self::assertTrue(stream_set_blocking($stream, true));

        $state = StreamBlockingState::capture($stream);
        self::assertTrue($state->makeNonBlocking());
        self::assertFalse(stream_get_meta_data($stream)['blocked']);

        self::assertTrue($state->restore());
        self::assertTrue(stream_get_meta_data($stream)['blocked']);

        fclose($stream);
        fclose($peer);
    }

    #[Test]
    public function restoresAnOriginallyNonBlockingStream(): void
    {
        [$stream, $peer] = $this->socketPair();
        self::assertTrue(stream_set_blocking($stream, false));

        $state = StreamBlockingState::capture($stream);
        self::assertTrue($state->makeNonBlocking());
        self::assertFalse(stream_get_meta_data($stream)['blocked']);

        self::assertTrue($state->restore());
        self::assertFalse(stream_get_meta_data($stream)['blocked']);

        fclose($stream);
        fclose($peer);
    }

    #[Test]
    public function restoreIsIdempotent(): void
    {
        [$stream, $peer] = $this->socketPair();

        $state = StreamBlockingState::capture($stream);

        self::assertTrue($state->restore());
        fclose($stream);
        self::assertTrue($state->restore());

        fclose($peer);
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return $pair;
    }
}
