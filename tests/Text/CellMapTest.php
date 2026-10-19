<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\CellMap;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CellMapTest extends TestCase
{
    private const PIECES = [
        ' ', ' ', ' ', 'a', 'bc', 'word', 'é', "e\u{0301}", "\u{0301}", '東', '京都', '👩‍👩‍👧', '🇦🇹', '❤️', "\u{200B}",
        "\t", "\e[31m", 'ß', 'Ж', '─', "\u{FEFF}", '😀', 'ｶ',
    ];

    #[Test]
    public function mixedTextBreaksWhereAWalkOverEveryGraphemeBreaks(): void
    {
        mt_srand(7);

        for ($sample = 0; $sample < 400; ++$sample) {
            $text = '';
            for ($piece = mt_rand(1, 30); $piece > 0; --$piece) {
                $text .= self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
            }

            foreach (range(2, 14) as $width) {
                self::assertSame(
                    self::walkedRows($text, $width),
                    CellMap::of($text)->rowsAt($width),
                    sprintf('%s at %d', json_encode($text), $width),
                );
            }
        }
    }

    #[Test]
    public function aRowOpeningWithItsBreakingSpaceKeepsTheWideGraphemeAtItsEdgeWhole(): void
    {
        $text = ' a東b';

        self::assertSame(self::walkedRows($text, 2), CellMap::of($text)->rowsAt(2));
        self::assertSame([[0, 0], [1, 4], [5, 1]], CellMap::of($text)->rowsAt(2));
    }

    #[Test]
    public function measuresAsWideAsItsGraphemes(): void
    {
        $text = "a東\u{0301} 👩‍👩‍👧\u{200B}";

        self::assertSame(CharWidth::stringWidth($text), CellMap::of($text)->width());
    }

    #[Test]
    public function splitsATextIntoGraphemesOnceForEveryWidth(): void
    {
        $text = str_repeat('Grüße aus 東京 ', 20);

        foreach (range(2, 30) as $width) {
            CellMap::of($text)->rowsAt($width);
        }

        self::assertSame(1, CellMap::measures());
    }

    /**
     * The rows a walk over every grapheme in turn gives, as Paragraph wrapped before it had a cell map.
     *
     * @return list<array{int, int}>
     */
    private static function walkedRows(string $text, int $maxWidth): array
    {
        $characters = Grapheme::split($text);
        $rows = [];
        $rowStart = 0;
        $currentWidth = 0;
        $lastSpaceOffset = -1;
        $widthAtLastSpace = 0;

        foreach ($characters as $index => $character) {
            $characterWidth = CharWidth::graphemeWidth($character);

            if ($characterWidth === 0) {
                continue;
            }

            if ($character === ' ') {
                $lastSpaceOffset = $index - $rowStart;
                $widthAtLastSpace = $currentWidth;
            }

            if ($currentWidth + $characterWidth > $maxWidth && $index > $rowStart) {
                if ($lastSpaceOffset >= 0) {
                    $rows[] = [$rowStart, $lastSpaceOffset];
                    $rowStart += $lastSpaceOffset + 1;
                    $currentWidth = $currentWidth - $widthAtLastSpace - 1 + $characterWidth;
                } else {
                    $rows[] = [$rowStart, $index - $rowStart];
                    $rowStart = $index;
                    $currentWidth = $characterWidth;
                }
                $lastSpaceOffset = -1;
                $widthAtLastSpace = 0;
                continue;
            }

            $currentWidth += $characterWidth;
        }

        $rows[] = [$rowStart, count($characters) - $rowStart];

        $byteAt = [0];
        foreach ($characters as $index => $character) {
            $byteAt[$index + 1] = $byteAt[$index] + strlen($character);
        }

        return array_map(
            static fn (array $row): array => [$byteAt[$row[0]], $byteAt[$row[0] + $row[1]] - $byteAt[$row[0]]],
            $rows,
        );
    }
}
