<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Text\AnsiTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsiScanningTest extends TestCase
{
    #[Test]
    #[DataProvider('sequences')]
    public function aSequenceEndsAtItsFinalByte(string $text, int $position, int $expected): void
    {
        self::assertSame($expected, AnsiTokenizer::sequenceEnd($text, $position));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function sequences(): iterable
    {
        yield 'colour' => ["\e[31mRed", 0, 5];
        yield 'several parameters' => ["\e[1;32mgo", 0, 7];
        yield 'reset with no parameters' => ["\e[mplain", 0, 3];
        yield 'cursor forward' => ["\e[5Chi", 0, 4];
        yield 'erase line before a word starting with m' => ["\e[2Kmid", 0, 4];
        yield 'private parameter' => ["\e[?25lhidden", 0, 6];
        yield 'partway along a line' => ["ab\e[7mc", 2, 6];
        yield 'nothing after the bracket' => ["\e[", 0, 2];
        yield 'no final byte before the end' => ["\e[31", 0, 4];
        yield 'character set designation' => ["\e(Bx", 0, 3];
        yield 'link closed by ST' => ["\e]8;;http://x\e\\link", 0, 15];
        yield 'lone escape at the end' => ["a\e", 1, 2];
    }
}
