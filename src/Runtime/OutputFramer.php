<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal
 */
final class OutputFramer
{
    private string $pending = '';

    public function __construct(
        private readonly int $chunkBytes,
        private readonly bool $framesLines,
    ) {
    }

    /**
     * @return list<string>
     */
    public function push(string $bytes): array
    {
        $text = $this->pending . $bytes;
        $ready = $this->framesLines ? self::wholeLinesLength($text) : 0;
        if (!$this->framesLines || \strlen($text) - $ready >= $this->chunkBytes) {
            $ready = self::wholeCharactersLength($text);
        }
        $this->pending = substr($text, $ready);

        return $this->chunksOf(substr($text, 0, $ready));
    }

    /**
     * @return list<string>
     */
    public function finish(string $bytes): array
    {
        $text = $this->pending . $bytes;
        $this->pending = '';

        return $this->chunksOf($text);
    }

    /**
     * @return list<string>
     */
    private function chunksOf(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $size = max(1, $this->chunkBytes);

        return $this->framesLines ? self::lineChunksOf($text, $size) : self::characterChunksOf($text, $size);
    }

    private static function wholeLinesLength(string $text): int
    {
        return \strlen($text) - strcspn(strrev($text), "\r\n");
    }

    private static function wholeCharactersLength(string $text): int
    {
        $length = \strlen($text);
        $start = $length - 1;
        while ($start >= 0 && $start > $length - 4 && self::isContinuationByte($text[$start])) {
            --$start;
        }
        if ($start < 0) {
            return $length;
        }

        $lead = \ord($text[$start]);
        $sequenceLength = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default => 1,
        };

        return $start + $sequenceLength > $length ? $start : $length;
    }

    /**
     * A line longer than the size is cut between characters.
     *
     * @return list<string>
     */
    private static function lineChunksOf(string $text, int $size): array
    {
        $chunks = [];
        while (\strlen($text) > $size) {
            $cut = self::wholeLinesLength(substr($text, 0, $size));
            if ($cut === 0) {
                $cut = \strlen(self::characterChunksOf(substr($text, 0, $size + 3), $size)[0]);
            }
            $chunks[] = substr($text, 0, $cut);
            $text = substr($text, $cut);
        }
        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private static function characterChunksOf(string $text, int $size): array
    {
        $chunks = [];
        while ($text !== '') {
            $length = \strlen($text);
            $cut = min($size, $length);
            while ($cut > 0 && $cut < $length && self::isContinuationByte($text[$cut])) {
                --$cut;
            }
            if ($cut === 0) {
                $cut = $size;
                while ($cut < $length && self::isContinuationByte($text[$cut])) {
                    ++$cut;
                }
            }
            $chunks[] = substr($text, 0, $cut);
            $text = substr($text, $cut);
        }

        return $chunks;
    }

    private static function isContinuationByte(string $byte): bool
    {
        return (\ord($byte) & 0xC0) === 0x80;
    }
}
