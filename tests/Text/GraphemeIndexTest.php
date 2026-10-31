<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Text\GraphemeIndex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphemeIndexTest extends TestCase
{
    private const PIECES = ['a', ' ', 'é', "e\u{0301}", '東', '👩‍👩‍👧', "\u{200B}", '🇦🇹', 'ß'];

    #[Test]
    public function answersWhereAWalkOverEveryGraphemeFindsTheSameAnswer(): void
    {
        mt_srand(11);

        for ($sample = 0; $sample < 200; ++$sample) {
            $text = '';
            for ($piece = mt_rand(0, 12); $piece > 0; --$piece) {
                $text .= self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
            }
            $graphemes = Grapheme::split($text);
            $index = GraphemeIndex::of($text);
            $columns = [0];
            foreach ($graphemes as $position => $grapheme) {
                $columns[] = $columns[$position] + CharWidth::graphemeWidth($grapheme);
            }

            self::assertSame(count($graphemes), $index->count);
            self::assertSame(end($columns), $index->width());
            foreach (range(0, count($graphemes)) as $position) {
                self::assertSame($columns[$position], $index->columnOf($position));
                self::assertSame(strlen(implode('', array_slice($graphemes, 0, $position))), $index->byteOf($position));
            }
            foreach (range(-1, end($columns) + 1) as $column) {
                self::assertSame(self::firstFrom($columns, $column), $index->firstFrom($column), json_encode($text) . " from $column");
                self::assertSame(self::lastUpTo($columns, $column), $index->lastUpTo($column), json_encode($text) . " up to $column");
            }
        }
    }

    /**
     * @param non-empty-list<int> $columns
     */
    private static function firstFrom(array $columns, int $column): int
    {
        foreach ($columns as $position => $start) {
            if ($start >= $column) {
                return $position;
            }
        }

        return count($columns) - 1;
    }

    /**
     * @param non-empty-list<int> $columns
     */
    private static function lastUpTo(array $columns, int $column): int
    {
        $last = 0;
        foreach ($columns as $position => $start) {
            if ($start <= $column) {
                $last = $position;
            }
        }

        return $last;
    }
}
