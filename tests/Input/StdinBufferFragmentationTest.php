<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\StdinBuffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StdinBufferFragmentationTest extends TestCase
{
    /**
     * @param list<string> $chunks
     */
    #[Test]
    #[DataProvider('splitInputs')]
    public function inputSplitAcrossReadsFramesAsItDoesWhole(string $input, array $chunks): void
    {
        self::assertSame(self::framed([$input], flushBetween: false), self::framed($chunks, flushBetween: false));
    }

    /**
     * Escape timeouts may change key interpretation, but must not lose bytes or alter paste content.
     *
     * @param list<string> $chunks
     */
    #[Test]
    #[DataProvider('splitInputsTheReaderTypes')]
    public function aTimeoutBetweenReadsLosesNoByteAndLeavesAPasteWhole(string $input, array $chunks): void
    {
        $whole = self::framed([$input], flushBetween: false);
        $split = self::framed($chunks, flushBetween: true);

        self::assertSame(
            [implode('', $whole['sequences']), $whole['pastes']],
            [implode('', $split['sequences']), $split['pastes']],
        );
    }

    /**
     * Swallow terminal replies whole; an incomplete paste marker can still be a key sequence until its final byte arrives.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function splitInputsTheReaderTypes(): iterable
    {
        foreach (self::inputs() as $name => $input) {
            if ($name === 'a terminal reply') {
                continue;
            }

            foreach (self::splitsOf($name, $input) as $case => [$whole, $chunks]) {
                if (!self::cutsAPasteStartMarker($chunks)) {
                    yield $case => [$whole, $chunks];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function splitInputs(): iterable
    {
        foreach (self::inputs() as $name => $input) {
            yield from self::splitsOf($name, $input);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function inputs(): array
    {
        return [
            'plain text' => 'hello',
            'typed UTF-8' => 'café 日本 😀',
            'arrows and function keys' => "\x1b[A\x1b[1;5C\x1bOP\x1b[15~",
            'kitty and modifyOtherKeys' => "\x1b[97;5u\x1b[27;2;13~",
            'SGR mouse' => "\x1b[<0;10;5M\x1b[<0;10;5m",
            'X10 mouse' => "\x1b[M !!x",
            'alt keys' => "\x1bx\x1b\r\x1b\x7f",
            'a paste' => "a\x1b[200~caf\xc3\xa9 \x1b[1;5u日\x1b[201~b",
            'a terminal reply' => "\x1b]11;rgb:0000/0000/0000\x07x\x1bP1\$r0m\x1b\\y",
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function splitsOf(string $name, string $input): iterable
    {
        for ($at = 1; $at < strlen($input); ++$at) {
            yield "$name split at $at" => [$input, [substr($input, 0, $at), substr($input, $at)]];
        }

        yield "$name byte by byte" => [$input, str_split($input)];
    }

    /**
     * @param list<string> $chunks
     */
    private static function cutsAPasteStartMarker(array $chunks): bool
    {
        $start = strpos(implode('', $chunks), "\x1b[200~");
        if ($start === false) {
            return false;
        }

        $offset = 0;
        foreach ($chunks as $chunk) {
            $offset += strlen($chunk);
            if ($offset > $start && $offset < $start + strlen("\x1b[200~")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $chunks
     *
     * @return array{sequences: list<string>, pastes: list<string>}
     */
    private static function framed(array $chunks, bool $flushBetween): array
    {
        $sequences = [];
        $pastes = [];
        $buffer = new StdinBuffer(
            onSequence: static function (string $sequence) use (&$sequences): void { $sequences[] = $sequence; },
            onPaste: static function (string $content) use (&$pastes): void { $pastes[] = $content; },
        );

        foreach ($chunks as $chunk) {
            $buffer->process($chunk);
            $delay = $buffer->flushDelay();
            if ($flushBetween && $delay !== null && $delay < StdinBuffer::PASTE_TIMEOUT) {
                $buffer->flush();
            }
        }
        $buffer->flush();

        return ['sequences' => $sequences, 'pastes' => $pastes];
    }
}
