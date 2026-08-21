<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\SgrParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a CSI sequence ends, and whether it was a colour. Reading it to the
 * final byte is what keeps the text of every other sequence out of the output,
 * so the exact offsets are worth stating rather than inferring from a screen.
 */
final class CsiScanningTest extends TestCase
{
    /**
     * @param array{int, ?string} $expected
     */
    #[Test]
    #[DataProvider('sequences')]
    public function aSequenceEndsAtItsFinalByteAndOnlyAnMIsAColour(string $text, int $position, array $expected): void
    {
        self::assertSame($expected, SgrParser::csiAt($text, $position));
    }

    /**
     * @return iterable<string, array{string, int, array{int, ?string}}>
     */
    public static function sequences(): iterable
    {
        yield 'colour' => ["\e[31mRed", 0, [5, '31']];
        yield 'several parameters' => ["\e[1;32mgo", 0, [7, '1;32']];
        yield 'reset with no parameters' => ["\e[mplain", 0, [3, '']];
        yield 'cursor forward is not a colour' => ["\e[5Chi", 0, [4, null]];
        yield 'erase line before a word starting with m' => ["\e[2Kmid", 0, [4, null]];
        yield 'private parameter' => ["\e[?25lhidden", 0, [6, null]];
        yield 'partway along a line' => ["ab\e[7mc", 2, [6, '7']];
        yield 'nothing after the bracket' => ["\e[", 0, [2, null]];
        yield 'no final byte before the end' => ["\e[31", 0, [4, null]];
    }
}
