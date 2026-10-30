<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reuse TUIOS's corpus of text known to break terminal UIs.
 */
final class HostileTextTest extends TestCase
{
    #[Test]
    #[DataProvider('hostileText')]
    public function noLineOfWrappedTextIsWiderThanTheWidthItWasGiven(string $text): void
    {
        foreach ([1, 2, 5, 20] as $width) {
            foreach (TextWrap::wrap($text, $width) as $line) {
                self::assertLessThanOrEqual(
                    $width,
                    CharWidth::stringWidth($line),
                    sprintf('Wrapping to %d produced a wider line for %s', $width, self::described($text)),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileText(): iterable
    {
        yield 'family emoji joined by ZWJ' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"];
        yield 'flag from regional indicators' => ["\u{1F1E9}\u{1F1EA}"];
        yield 'combining marks' => ["e\u{0301}a\u{0308}o\u{0302}"];
        yield 'variation selector' => ["\u{2764}\u{FE0F}"];
        yield 'zero width space' => ["a\u{200B}b"];
        yield 'right to left' => ["\u{05D0}\u{05D1}\u{05D2} shalom"];
        yield 'an escape sequence typed as a name' => ["\x1b[31mred"];
        yield 'a NUL byte' => ["a\0b"];
        yield 'a delete byte' => ["a\x7fb"];
        yield 'wide CJK' => ['日本語のテキスト'];
        yield 'mixed wide and narrow' => ['ab日本cd語ef'];
        yield 'a lone continuation byte' => ["a\xC3(b"];
        yield 'a tab' => ["a\tb"];
    }

    private static function described(string $text): string
    {
        return json_encode($text, JSON_UNESCAPED_UNICODE) ?: 'unprintable';
    }
}
