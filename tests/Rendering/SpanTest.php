<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpanTest extends TestCase
{
    #[Test]
    public function styledFactoryStoresContentAndStyle(): void
    {
        $style = new Style(foreground: Color::ansi(1));
        $span = Span::styled('hello', $style);

        self::assertSame('hello', $span->content);
        self::assertSame($style, $span->style);
    }

    #[Test]
    public function defaultStyleIsNull(): void
    {
        $span = new Span('plain');

        self::assertSame('plain', $span->content);
        self::assertNull($span->style);
    }

    #[Test]
    public function aTokenSpanCarriesNoStyleUntilAThemeResolvesIt(): void
    {
        $span = Span::token('hello', StyleToken::Accent);

        self::assertNull($span->style);
        self::assertSame(StyleToken::Accent, $span->token);
    }

    #[Test]
    public function resolvingATokenSpanAdoptsTheThemesStyleAndDropsTheToken(): void
    {
        $theme = Theme::dark();

        $resolved = Span::token('hello', StyleToken::Accent)->withResolvedToken($theme);

        self::assertSame($theme->accent, $resolved->style);
        self::assertNull($resolved->token);
        self::assertSame('hello', $resolved->content);
    }

    #[Test]
    public function theSameTokenSpanResolvesToWhicheverThemeIsActive(): void
    {
        $span = Span::token('hello', StyleToken::Accent);

        self::assertEquals(
            Theme::light()->accent,
            $span->withResolvedToken(Theme::light())->style,
        );
        self::assertEquals(
            Theme::dark()->accent,
            $span->withResolvedToken(Theme::dark())->style,
        );
    }

    #[Test]
    public function attributesAddedToATokenSpanSurviveResolutionOnTopOfTheThemeColour(): void
    {
        $theme = Theme::dark();

        $resolved = Span::token('hello', StyleToken::Accent)->bold()->withResolvedToken($theme);

        self::assertSame($theme->accent->foreground, $resolved->style?->foreground);
        self::assertSame(Attr::BOLD, $resolved->style?->attributes);
    }

    /**
     * @return iterable<string, array{callable(Span): Span, int}>
     */
    public static function attributeModifiers(): iterable
    {
        yield 'bold' => [static fn (Span $span): Span => $span->bold(), Attr::BOLD];
        yield 'faint' => [static fn (Span $span): Span => $span->faint(), Attr::FAINT];
        yield 'italic' => [static fn (Span $span): Span => $span->italic(), Attr::ITALIC];
        yield 'underline' => [static fn (Span $span): Span => $span->underline(), Attr::UNDERLINE];
        yield 'reverse' => [static fn (Span $span): Span => $span->reverse(), Attr::REVERSE];
        yield 'strikethrough' => [static fn (Span $span): Span => $span->strikethrough(), Attr::STRIKETHROUGH];
    }

    /**
     * @param callable(Span): Span $modifier
     */
    #[Test]
    #[DataProvider('attributeModifiers')]
    public function eachModifierSetsItsOwnAttributeBit(callable $modifier, int $expected): void
    {
        self::assertSame($expected, $modifier(new Span('hello'))->style?->attributes);
    }

    #[Test]
    public function attributesAccumulateAcrossCalls(): void
    {
        $span = (new Span('hello'))->bold()->italic()->underline();

        self::assertSame(Attr::BOLD | Attr::ITALIC | Attr::UNDERLINE, $span->style?->attributes);
    }

    #[Test]
    public function severalAttributeBitsCanBeSetInOneCall(): void
    {
        $span = (new Span('hello'))->withAttributes(Attr::BOLD | Attr::STRIKETHROUGH);

        self::assertSame(Attr::BOLD | Attr::STRIKETHROUGH, $span->style?->attributes);
    }

    #[Test]
    public function anAttributeAddedToAStyledSpanKeepsTheStylesColour(): void
    {
        $red = Color::ansi(1);

        $span = Span::styled('hello', new Style(foreground: $red))->faint();

        self::assertSame($red, $span->style?->foreground);
        self::assertSame(Attr::FAINT, $span->style?->attributes);
    }

    #[Test]
    public function aSpanWithoutATokenIsUnchangedByResolution(): void
    {
        $span = Span::styled('hello', new Style(foreground: Color::ansi(1)));

        self::assertSame($span, $span->withResolvedToken(Theme::dark()));
    }

    #[Test]
    public function aPlainSpanIsUnchangedByResolution(): void
    {
        $span = new Span('hello');

        self::assertSame($span, $span->withResolvedToken(Theme::dark()));
    }

    #[Test]
    public function widthReturnsVisualWidth(): void
    {
        $span = new Span('hello');
        self::assertSame(5, $span->width());
    }

    #[Test]
    public function widthHandlesMultibyte(): void
    {
        $span = new Span('日本語');
        self::assertSame(6, $span->width());
    }

    #[Test]
    public function widthReturnsTwoPerCjkCharacter(): void
    {
        $span = new Span('漢字');
        self::assertSame(4, $span->width());
    }

    #[Test]
    public function widthReturnsMixedAsciiAndCjk(): void
    {
        $span = new Span('ab漢');
        self::assertSame(4, $span->width());
    }

    #[Test]
    public function widthTreatsCombiningMarksAsZeroWidth(): void
    {
        $span = new Span("e\xCC\x81"); // e + combining acute accent (U+0301)
        self::assertSame(1, $span->width());
    }

    #[Test]
    public function aSpanCarryingNoInkOfItsOwnIsPlacedUnchanged(): void
    {
        $panel = new Style(background: Color::hex('3E4451'));
        $inherited = new Span('plain');
        $token = Span::token('accented', StyleToken::Accent);

        self::assertNull($inherited->readableOn($panel)->style, 'nothing of its own to lift');
        self::assertSame(StyleToken::Accent, $token->readableOn($panel)->token, 'a token still resolves later');
    }

    #[Test]
    public function aSpanOnAPanelLiftsItsOwnInkOffThatBackground(): void
    {
        $panel = new Style(background: Color::hex('3E4451'));
        $comment = Span::styled('// quiet', new Style(foreground: Color::hex('5C6370')));

        $placed = $comment->readableOn($panel);

        self::assertSame('// quiet', $placed->content);
        self::assertNotNull($placed->style?->foreground);
        self::assertGreaterThanOrEqual(4.5, $placed->style->foreground->contrastRatio(Color::hex('3E4451')));
    }
}
