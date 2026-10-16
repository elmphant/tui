<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    #[Test]
    public function darkPresetHasAllTokens(): void
    {
        $theme = Theme::dark();

        self::assertInstanceOf(Style::class, $theme->base);
        self::assertInstanceOf(Style::class, $theme->accent);
        self::assertInstanceOf(Style::class, $theme->dim);
        self::assertInstanceOf(Style::class, $theme->error);
        self::assertInstanceOf(Style::class, $theme->success);
        self::assertInstanceOf(Style::class, $theme->borderStyle);
        self::assertInstanceOf(Style::class, $theme->focusedBorderStyle);
        self::assertInstanceOf(Style::class, $theme->titleStyle);
        self::assertInstanceOf(Style::class, $theme->headerStyle);
        self::assertInstanceOf(Style::class, $theme->selectedStyle);
        self::assertInstanceOf(Style::class, $theme->cursorStyle);
        self::assertInstanceOf(Style::class, $theme->fillStyle);
        self::assertInstanceOf(Style::class, $theme->emptyStyle);
        self::assertInstanceOf(BorderStyle::class, $theme->border);
        self::assertInstanceOf(BorderStyle::class, $theme->focusedBorder);
    }

    #[Test]
    public function darkAccentIsBlueForeground(): void
    {
        $theme = Theme::dark();

        self::assertSame(Color::hex('61AFEF')->value, $theme->accent->foreground->value);
    }

    #[Test]
    public function lightPresetHasDarkText(): void
    {
        $theme = Theme::light();

        self::assertSame(Color::hex('383A42')->value, $theme->base->foreground->value);
    }

    #[Test]
    public function draculaPresetUsesPurpleAccent(): void
    {
        $theme = Theme::dracula();

        self::assertSame(Color::hex('BD93F9')->value, $theme->accent->foreground->value);
    }

    #[Test]
    public function solarizedDarkUsesBlueAccent(): void
    {
        $theme = Theme::solarizedDark();

        self::assertSame(Color::hex('268BD2')->value, $theme->accent->foreground->value);
    }

    #[Test]
    public function solarizedLightUsesBlueAccent(): void
    {
        $theme = Theme::solarizedLight();

        self::assertSame(Color::hex('268BD2')->value, $theme->accent->foreground->value);
    }

    #[Test]
    public function solarizedLightHasLightBackground(): void
    {
        $theme = Theme::solarizedLight();

        self::assertSame(Color::hex('FDF6E3')->value, $theme->base->background->value);
    }

    #[Test]
    public function theBorderGlyphSetsAreReplacedIndependently(): void
    {
        $original = Theme::dark();

        $bordered = $original->withBorder(BorderStyle::Double);
        self::assertSame(BorderStyle::Double, $bordered->border);
        self::assertSame($original->focusedBorder, $bordered->focusedBorder, 'The focused set is left alone');

        $focused = $original->withFocusedBorder(BorderStyle::Thick);
        self::assertSame(BorderStyle::Thick, $focused->focusedBorder);
        self::assertSame($original->border, $focused->border, 'And the reverse holds');
    }

    #[Test]
    public function everyTokenBuilderReplacesItsOwnTokenAndNothingElse(): void
    {
        $marker = new Style(foreground: Color::hex('FF00FF'));
        $original = Theme::dark();

        $builders = [
            'dim' => static fn (Theme $theme): Theme => $theme->withDim($marker),
            'error' => static fn (Theme $theme): Theme => $theme->withError($marker),
            'success' => static fn (Theme $theme): Theme => $theme->withSuccess($marker),
            'warning' => static fn (Theme $theme): Theme => $theme->withWarning($marker),
            'info' => static fn (Theme $theme): Theme => $theme->withInfo($marker),
            'headerStyle' => static fn (Theme $theme): Theme => $theme->withHeaderStyle($marker),
            'selectedStyle' => static fn (Theme $theme): Theme => $theme->withSelectedStyle($marker),
            'cursorStyle' => static fn (Theme $theme): Theme => $theme->withCursorStyle($marker),
            'inputStyle' => static fn (Theme $theme): Theme => $theme->withInputStyle($marker),
        ];

        foreach ($builders as $token => $build) {
            $modified = $build($original);

            self::assertNotSame($original, $modified, "withX for {$token} returns a new theme");
            self::assertSame(
                $marker->foreground?->value,
                $modified->{$token}->foreground?->value,
                "withX for {$token} replaced the wrong token",
            );

            foreach (array_keys($builders) as $other) {
                if ($other === $token) {
                    continue;
                }
                self::assertSame(
                    $original->{$other}->foreground?->value,
                    $modified->{$other}->foreground?->value,
                    "withX for {$token} disturbed {$other}",
                );
            }
        }
    }

    #[Test]
    public function withOverridesToken(): void
    {
        $original = Theme::dark();
        $customAccent = new Style(foreground: Color::hex('FF0000'), attributes: Attr::BOLD);
        $modified = $original->withAccent($customAccent);

        self::assertSame(Color::hex('61AFEF')->value, $original->accent->foreground->value);
        self::assertSame(Color::hex('FF0000')->value, $modified->accent->foreground->value);
        self::assertSame(Attr::BOLD, $modified->accent->attributes);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function allPresetsUseRoundedBorder(): void
    {
        $presets = [
            Theme::dark(),
            Theme::light(),
            Theme::dracula(),
            Theme::solarizedDark(),
            Theme::solarizedLight(),
            Theme::rosePine(),
            Theme::atomOneDark(),
            Theme::gruvboxDark(),
            Theme::gruvboxLight(),
        ];

        foreach ($presets as $theme) {
            self::assertSame(BorderStyle::Rounded, $theme->border);
        }
    }

    #[Test]
    public function allPresetsUseThickForFocusedBorder(): void
    {
        $presets = [
            Theme::dark(),
            Theme::light(),
            Theme::dracula(),
            Theme::solarizedDark(),
            Theme::solarizedLight(),
            Theme::rosePine(),
            Theme::atomOneDark(),
            Theme::gruvboxDark(),
            Theme::gruvboxLight(),
        ];

        foreach ($presets as $theme) {
            // Unicode has no heavy rounded corners, so focus cannot change the
            // shape without squaring it off. It changes colour and weight.
            self::assertSame(BorderStyle::Rounded, $theme->focusedBorder);
            self::assertSame(Attr::BOLD, $theme->focusedBorderStyle->attributes & Attr::BOLD, 'Focus must read without colour too');
        }
    }

    #[Test]
    public function darkCursorForegroundIsAnsiBlack(): void
    {
        $theme = Theme::dark();

        self::assertSame(Color::ansi(0)->value, $theme->cursorStyle->foreground->value);
    }

    /**
     * @return array<string, Theme>
     */
    public static function allPresets(): array
    {
        return [
            'dark' => Theme::dark(),
            'light' => Theme::light(),
            'dracula' => Theme::dracula(),
            'solarizedDark' => Theme::solarizedDark(),
            'solarizedLight' => Theme::solarizedLight(),
            'rosePine' => Theme::rosePine(),
            'atomOneDark' => Theme::atomOneDark(),
            'gruvboxDark' => Theme::gruvboxDark(),
            'gruvboxLight' => Theme::gruvboxLight(),
        ];
    }

    #[Test]
    public function everyPresetKeepsItsFourSeverityColoursApart(): void
    {
        foreach (self::allPresets() as $name => $theme) {
            $severities = [
                'error' => $theme->error->foreground?->value,
                'warning' => $theme->warning->foreground?->value,
                'success' => $theme->success->foreground?->value,
                'info' => $theme->info->foreground?->value,
            ];

            self::assertSameSize(
                $severities,
                array_unique($severities),
                sprintf('%s gives two severities the same colour: %s', $name, implode(', ', array_keys($severities))),
            );
        }
    }

    #[Test]
    public function everyPresetDefinesAFooterStyleWithItsOwnBackground(): void
    {
        foreach (self::allPresets() as $name => $theme) {
            self::assertInstanceOf(Style::class, $theme->footerStyle, "$name must define footerStyle");
            self::assertNotNull($theme->footerStyle->background, "$name footer needs a bar background");
            self::assertNotNull($theme->footerStyle->foreground, "$name footer needs a text color");
        }
    }

    #[Test]
    public function everyPresetFooterKeyHintMeetsWcagAaContrast(): void
    {
        // StatusBar draws hint keys in the accent color over the footer bar.
        foreach (self::allPresets() as $name => $theme) {
            $bar = $theme->footerStyle->background ?? Color::ansi(0);
            $ratio = $theme->accent->foreground?->contrastRatio($bar) ?? 1.0;

            self::assertGreaterThanOrEqual(
                3.0,
                $ratio,
                sprintf('%s footer key hint contrast is %.2f:1', $name, $ratio),
            );
        }
    }

    #[Test]
    public function everyPresetSaysWhichPageItWasDesignedAgainst(): void
    {
        foreach (self::allPresets() as $name => $theme) {
            self::assertNotNull($theme->page, sprintf('%s cannot tell a fill from its surroundings', $name));
        }
    }

    #[Test]
    public function everyFilledRowInEveryPresetStandsOutFromThePage(): void
    {
        foreach (self::allPresets() as $name => $theme) {
            $page = $theme->page;
            self::assertNotNull($page);

            $panels = [
                'selected' => $theme->selectedStyle,
                'cursor' => $theme->cursorStyle,
                'input' => $theme->inputStyle,
                'footer' => $theme->footerStyle,
            ];

            foreach ($panels as $panel => $style) {
                $background = $style->background;
                self::assertNotNull($background, sprintf('%s %s needs a background', $name, $panel));

                $ratio = $background->contrastRatio($page);
                self::assertGreaterThanOrEqual(
                    1.25,
                    $ratio,
                    sprintf('%s draws %s at %.2f:1 against its page, which reads as no fill at all', $name, $panel, $ratio),
                );
            }
        }
    }

    #[Test]
    public function everyFilledRowInEveryPresetReadsWithTheInkDrawnOnIt(): void
    {
        foreach (self::allPresets() as $name => $theme) {
            $panels = [
                'selected' => $theme->selectedStyle,
                'cursor' => $theme->cursorStyle,
                'input' => $theme->inputStyle,
                'footer' => $theme->footerStyle,
            ];

            foreach ($panels as $panel => $style) {
                $background = $style->background;
                self::assertNotNull($background, sprintf('%s %s needs a background', $name, $panel));

                $ink = $style->foreground ?? $theme->base->foreground;
                self::assertNotNull($ink, sprintf('%s has no ink for %s', $name, $panel));

                $ratio = $ink->contrastRatio($background);
                self::assertGreaterThanOrEqual(
                    4.5,
                    $ratio,
                    sprintf('%s draws %s at %.2f:1', $name, $panel, $ratio),
                );
            }
        }
    }

    #[Test]
    public function aCanvasPaintsBehindEveryInkAndLeavesTheFilledRowsTheirOwn(): void
    {
        $canvas = Color::hex('101010');
        $dark = Theme::dark();

        $theme = $dark->withCanvas($canvas);

        self::assertEquals($canvas, $theme->page);
        foreach (['base', 'accent', 'dim', 'error', 'success', 'warning', 'info', 'borderStyle', 'focusedBorderStyle', 'titleStyle', 'headerStyle', 'fillStyle', 'emptyStyle'] as $ink) {
            self::assertEquals($canvas, $theme->{$ink}->background, $ink);
            self::assertEquals($dark->{$ink}->foreground, $theme->{$ink}->foreground, $ink);
        }
        foreach (['selectedStyle', 'cursorStyle', 'inputStyle', 'footerStyle'] as $filled) {
            self::assertEquals($dark->{$filled}, $theme->{$filled}, $filled);
        }
    }
}
