<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Compare the ASCII fast path with full grapheme segmentation, including clusters that cross ASCII boundaries in either direction.
 */
final class CharWidthSegmentationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function strings(): iterable
    {
        yield 'empty' => [''];
        yield 'pure ascii' => ['workload-000042 running'];
        yield 'single space' => [' '];
        yield 'bullet only' => ['·'];
        yield 'bullet at start' => ['· workload'];
        yield 'bullet at end' => ['workload ·'];
        yield 'bullet in the middle' => ['team-07 · v3.5'];
        yield 'many bullets' => ['a · b · c · d · e'];
        yield 'adjacent bullets' => ['a ·· b'];
        yield 'ascii base plus combining acute' => ["cafe\u{0301} latte"];
        yield 'combining marks only' => ["\u{0301}\u{0302}"];
        yield 'ascii then two combining marks' => ["e\u{0301}\u{0308}x"];
        yield 'prepend then ascii base' => ["\u{0600}1 item"];
        yield 'crlf' => ["line\r\nnext"];
        yield 'tab and newline' => ["a\tb\nc"];
        yield 'cjk wide' => ['CPU 使用率 45%'];
        yield 'emoji with vs16' => ["status \u{2714}\u{FE0F} ok"];
        yield 'emoji without vs16' => ["status \u{2714} ok"];
        yield 'text presentation selector' => ["status \u{2714}\u{FE0E} ok"];
        yield 'zwj sequence' => ["family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} here"];
        yield 'regional indicators' => ["flag \u{1F1E9}\u{1F1EA} de"];
        yield 'keycap' => ["press #\u{FE0F}\u{20E3} now"];
        yield 'skin tone modifier' => ["wave \u{1F44B}\u{1F3FD} hi"];
        yield 'zero width space' => ["a\u{200B}b"];
        yield 'nbsp' => ["a\u{00A0}b"];
        yield 'control character' => ["a\x01b"];
        yield 'del character' => ["a\x7fb"];
        yield 'high control range' => ["a\u{0085}b"];
        yield 'invalid utf8 lead' => ["a\xC3b"];
        yield 'invalid utf8 continuation' => ["a\x80b"];
        yield 'byte forming no character' => ["ab\xFFcd"];
        yield 'truncated multibyte tail' => ["tr\xC3\xA4nc\xC3"];
        yield 'leads with a bad byte' => ["\xFFab"];
        yield 'long ascii with one bullet' => [str_repeat('abcdefgh ', 40) . '·'];
        yield 'alternating' => [str_repeat('a·', 30)];
        yield 'ends with combining mark' => ["abc\u{0301}"];
        yield 'starts with combining mark' => ["\u{0301}abc"];
    }

    #[Test]
    #[DataProvider('strings')]
    public function stringWidthAgreesWithAWholeStringGraphemeWalk(string $input): void
    {
        self::assertSame(self::referenceWidth($input), CharWidth::stringWidth($input));
    }

    #[Test]
    #[DataProvider('strings')]
    public function truncateAgreesWithAWholeStringGraphemeWalk(string $input): void
    {
        foreach ([0, 1, 2, 3, 5, 8, 13, 400] as $maxWidth) {
            self::assertSame(
                self::referenceTruncate($input, $maxWidth),
                CharWidth::truncateToWidth($input, $maxWidth),
                sprintf('truncateToWidth(..., %d)', $maxWidth),
            );
        }
    }

    #[Test]
    public function stringWidthAgreesOnRandomlyAssembledStrings(): void
    {
        $alphabet = [
            'a', 'Z', '9', ' ', '-', '·', '→', '使', "\u{0301}", "\u{200D}",
            "\u{FE0F}", "\u{1F600}", "\r", "\n", "\x01", "\u{00A0}", '',
        ];

        mt_srand(20260802);
        for ($case = 0; $case < 2_000; ++$case) {
            $input = '';
            $length = mt_rand(0, 12);
            for ($index = 0; $index < $length; ++$index) {
                $input .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            self::assertSame(
                self::referenceWidth($input),
                CharWidth::stringWidth($input),
                sprintf('input bytes: %s', bin2hex($input)),
            );
        }
    }

    private static function referenceWidth(string $text): int
    {
        $width = 0;
        foreach (Grapheme::split($text) as $grapheme) {
            $width += CharWidth::graphemeWidth($grapheme);
        }

        return $width;
    }

    private static function referenceTruncate(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        $width = 0;
        $result = '';
        foreach (Grapheme::split($text) as $grapheme) {
            $graphemeWidth = CharWidth::graphemeWidth($grapheme);
            if ($width + $graphemeWidth > $maxWidth) {
                break;
            }
            $result .= $grapheme;
            $width += $graphemeWidth;
        }

        return $result;
    }
}
