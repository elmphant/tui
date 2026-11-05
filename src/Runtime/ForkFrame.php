<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal what a forked child writes to its parent: a type byte, a length and a serialized payload
 */
enum ForkFrame: string
{
    case Message = 'M';
    case Failure = 'F';
    case End = 'E';

    private const HEADER = 5;

    public function encode(string $payload = ''): string
    {
        return $this->value . pack('N', \strlen($payload)) . $payload;
    }

    /**
     * Take every whole frame off the front of $buffer, leaving a partial one there for the next read.
     *
     * @return list<array{self, string}>
     */
    public static function takeFrom(string &$buffer): array
    {
        $frames = [];

        while (\strlen($buffer) >= self::HEADER) {
            /** @var array{1: int} $length */
            $length = unpack('N', substr($buffer, 1, 4));
            if (\strlen($buffer) < self::HEADER + $length[1]) {
                break;
            }

            $frames[] = [self::from($buffer[0]), substr($buffer, self::HEADER, $length[1])];
            $buffer = substr($buffer, self::HEADER + $length[1]);
        }

        return $frames;
    }
}
