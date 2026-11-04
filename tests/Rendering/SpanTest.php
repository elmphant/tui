<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\AnsiColor;
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
        $style = new Style(foreground: Color::ansi(AnsiColor::Red));
        $span = Span::styled('hello', $style);

        self::assertSame('hello', $span->content);
        self::assertSame($style, $span->style);
    }

    #[Test]
    public function aBoldTokenSpanKeepsTheThemesColourAndAddsBold(): void
    {
        $theme = Theme::dark();

        $resolved = Span::token('Deployed', StyleToken::Success)->bold()->resolvedIn($theme);

        self::assertSame($theme->success->foreground, $resolved->style?->foreground);
        self::assertSame(Attr::BOLD, ($resolved->style?->attributes ?? 0) & Attr::BOLD);
    }

    #[Test]
    public function aLinkedTokenSpanKeepsTheThemesColourAndTheLink(): void
    {
        $theme = Theme::dark();

        $resolved = Span::token('docs', StyleToken::Accent)->withLink('https://example.com')->resolvedIn($theme);

        self::assertSame($theme->accent->foreground, $resolved->style?->foreground);
        self::assertSame('https://example.com', $resolved->style?->link);
    }

    #[Test]
    public function aPlainSpanHasNeitherAStyleNorAToken(): void
    {
        $span = Span::raw('plain');

        self::assertSame('plain', $span->content);
        self::assertNull($span->style);
        self::assertNull($span->token);
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

        $resolved = Span::token('hello', StyleToken::Accent)->resolvedIn($theme);

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
            $span->resolvedIn(Theme::light())->style,
        );
        self::assertEquals(
            Theme::dark()->accent,
            $span->resolvedIn(Theme::dark())->style,
        );
    }

    #[Test]
    public function attributesAddedToATokenSpanSurviveResolutionOnTopOfTheThemeColour(): void
    {
        $theme = Theme::dark();

        $resolved = Span::token('hello', StyleToken::Accent)->bold()->resolvedIn($theme);

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
        self::assertSame($expected, $modifier(Span::raw('hello'))->style?->attributes);
    }

    #[Test]
    public function attributesAccumulateAcrossCalls(): void
    {
        $span = Span::raw('hello')->bold()->italic()->underline();

        self::assertSame(Attr::BOLD | Attr::ITALIC | Attr::UNDERLINE, $span->style?->attributes);
    }

    #[Test]
    public function severalAttributeBitsCanBeSetInOneCall(): void
    {
        $span = Span::raw('hello')->withAttributes(Attr::BOLD | Attr::STRIKETHROUGH);

        self::assertSame(Attr::BOLD | Attr::STRIKETHROUGH, $span->style?->attributes);
    }

    #[Test]
    public function settingASpansAttributesReplacesTheOnesBefore(): void
    {
        $span = Span::raw('hello')->bold()->withAttributes(Attr::ITALIC);

        self::assertSame(Attr::ITALIC, $span->style?->attributes);
    }

    #[Test]
    public function addingAttributesToASpanKeepsTheOnesBefore(): void
    {
        $span = Span::raw('hello')->bold()->addAttributes(Attr::ITALIC);

        self::assertSame(Attr::BOLD | Attr::ITALIC, $span->style?->attributes);
    }

    #[Test]
    public function anAttributeAddedToAStyledSpanKeepsTheStylesColour(): void
    {
        $red = Color::ansi(AnsiColor::Red);

        $span = Span::styled('hello', new Style(foreground: $red))->faint();

        self::assertSame($red, $span->style?->foreground);
        self::assertSame(Attr::FAINT, $span->style?->attributes);
    }

    #[Test]
    public function aSpanWithoutATokenIsUnchangedByResolution(): void
    {
        $span = Span::styled('hello', new Style(foreground: Color::ansi(AnsiColor::Red)));

        self::assertSame($span, $span->resolvedIn(Theme::dark()));
    }

    #[Test]
    public function aPlainSpanIsUnchangedByResolution(): void
    {
        $span = Span::raw('hello');

        self::assertSame($span, $span->resolvedIn(Theme::dark()));
    }

    #[Test]
    public function widthReturnsVisualWidth(): void
    {
        $span = Span::raw('hello');
        self::assertSame(5, $span->width());
    }

    #[Test]
    public function widthHandlesMultibyte(): void
    {
        $span = Span::raw('日本語');
        self::assertSame(6, $span->width());
    }

    #[Test]
    public function widthReturnsTwoPerCjkCharacter(): void
    {
        $span = Span::raw('漢字');
        self::assertSame(4, $span->width());
    }

    #[Test]
    public function widthReturnsMixedAsciiAndCjk(): void
    {
        $span = Span::raw('ab漢');
        self::assertSame(4, $span->width());
    }

    #[Test]
    public function widthTreatsCombiningMarksAsZeroWidth(): void
    {
        $span = Span::raw("e\xCC\x81"); // e + combining acute accent (U+0301)
        self::assertSame(1, $span->width());
    }

    #[Test]
    public function aSpanCarryingNoInkOfItsOwnIsPlacedUnchanged(): void
    {
        $panel = new Style(background: Color::hex('3E4451'));
        $inherited = Span::raw('plain');
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
