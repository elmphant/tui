<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Core\Cloneable;

final readonly class Theme
{
    use Cloneable;

    /**
     * Bottom-bar style for Footer and StatusBar.
     *
     * Defaults to full-strength body text on the selection background, because
     * dim text on that background has too little contrast to read. Resolved
     * once at construction: a theme built by changing base or selectedStyle
     * afterwards keeps this style, so set it explicitly when recolouring those.
     */
    public Style $footerStyle;

    /**
     * Defaults to the title amber, which is every bundled palette's warning hue.
     */
    public Style $warning;

    public Style $info;

    public function __construct(
        public Style $base,
        public Style $accent,
        public Style $dim,
        public Style $error,
        public Style $success,
        public Style $borderStyle,
        public Style $focusedBorderStyle,
        public Style $titleStyle,
        public Style $headerStyle,
        public Style $selectedStyle,
        public Style $cursorStyle,
        public Style $fillStyle,
        public Style $emptyStyle,
        public Style $inputStyle,
        ?Style $footerStyle = null,
        ?Style $warning = null,
        ?Style $info = null,
        public BorderStyle $border = BorderStyle::Rounded,
        // Unicode has no heavy rounded corners; focus is carried by colour + bold.
        public BorderStyle $focusedBorder = BorderStyle::Rounded,
    ) {
        $this->footerStyle = $footerStyle ?? new Style(
            foreground: $base->foreground ?? Color::ansi(7),
            background: $selectedStyle->background ?? Color::ansi(0),
        );
        $this->warning = $warning ?? $titleStyle->withAttributes($titleStyle->attributes & ~Attr::BOLD);
        $this->info = $info ?? $accent;
    }

    public function withBase(Style $base): self
    {
        return $this->cloneWith(base: $base);
    }

    public function withAccent(Style $accent): self
    {
        return $this->cloneWith(accent: $accent);
    }

    public function withDim(Style $dim): self
    {
        return $this->cloneWith(dim: $dim);
    }

    public function withError(Style $error): self
    {
        return $this->cloneWith(error: $error);
    }

    public function withSuccess(Style $success): self
    {
        return $this->cloneWith(success: $success);
    }

    public function withWarning(Style $warning): self
    {
        return $this->cloneWith(warning: $warning);
    }

    public function withInfo(Style $info): self
    {
        return $this->cloneWith(info: $info);
    }

    public function withBorderStyle(Style $style): self
    {
        return $this->cloneWith(borderStyle: $style);
    }

    public function withFocusedBorderStyle(Style $style): self
    {
        return $this->cloneWith(focusedBorderStyle: $style);
    }

    public function withTitleStyle(Style $style): self
    {
        return $this->cloneWith(titleStyle: $style);
    }

    public function withHeaderStyle(Style $style): self
    {
        return $this->cloneWith(headerStyle: $style);
    }

    public function withSelectedStyle(Style $style): self
    {
        return $this->cloneWith(selectedStyle: $style);
    }

    public function withCursorStyle(Style $style): self
    {
        return $this->cloneWith(cursorStyle: $style);
    }

    public function withFillStyle(Style $style): self
    {
        return $this->cloneWith(fillStyle: $style);
    }

    public function withEmptyStyle(Style $style): self
    {
        return $this->cloneWith(emptyStyle: $style);
    }

    public function withInputStyle(Style $style): self
    {
        return $this->cloneWith(inputStyle: $style);
    }

    public function withFooterStyle(Style $style): self
    {
        return $this->cloneWith(footerStyle: $style);
    }

    public function withBorder(BorderStyle $border): self
    {
        return $this->cloneWith(border: $border);
    }

    public function withFocusedBorder(BorderStyle $border): self
    {
        return $this->cloneWith(focusedBorder: $border);
    }

    /**
     * One option row of a select, radio group or checkbox group, where marked
     * means selected or ticked.
     */
    public function optionStyle(bool $isCursor, bool $isMarked, bool $focused): Style
    {
        return match (true) {
            $isCursor => $this->cursorStyle,
            $isMarked => $this->accent,
            $focused => $this->base,
            default => $this->dim,
        };
    }

    public static function dark(): self
    {
        return new self(
            base: new Style(foreground: Color::hex('ABB2BF')),
            accent: new Style(foreground: Color::hex('61AFEF')),
            dim: new Style(foreground: Color::hex('5C6370')),
            error: new Style(foreground: Color::hex('E06C75')),
            success: new Style(foreground: Color::hex('98C379')),
            borderStyle: new Style(foreground: Color::hex('5C6370')),
            focusedBorderStyle: new Style(foreground: Color::hex('61AFEF'), attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('E5C07B'), attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('E5C07B'), attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('3E4451')),
            cursorStyle: new Style(foreground: Color::ansi(0), background: Color::hex('61AFEF')),
            fillStyle: new Style(foreground: Color::hex('61AFEF')),
            emptyStyle: new Style(foreground: Color::hex('5C6370')),
            inputStyle: new Style(foreground: Color::hex('ABB2BF'), background: Color::hex('3E4451')),
        );
    }

    public static function light(): self
    {
        return new self(
            base: new Style(foreground: Color::hex('383A42')),
            accent: new Style(foreground: Color::hex('4078F2')),
            dim: new Style(foreground: Color::hex('A0A1A7')),
            error: new Style(foreground: Color::hex('E45649')),
            success: new Style(foreground: Color::hex('50A14F')),
            borderStyle: new Style(foreground: Color::hex('D3D3D3')),
            focusedBorderStyle: new Style(foreground: Color::hex('4078F2'), attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('986801'), attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('986801'), attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('E5E5E6')),
            cursorStyle: new Style(foreground: Color::hex('FAFAFA'), background: Color::hex('4078F2')),
            fillStyle: new Style(foreground: Color::hex('4078F2')),
            emptyStyle: new Style(foreground: Color::hex('D3D3D3')),
            inputStyle: new Style(foreground: Color::hex('383A42'), background: Color::hex('E5E5E6')),
        );
    }

    public static function dracula(): self
    {
        return new self(
            base: new Style(foreground: Color::hex('F8F8F2')),
            accent: new Style(foreground: Color::hex('BD93F9')),
            dim: new Style(foreground: Color::hex('6272A4')),
            error: new Style(foreground: Color::hex('FF5555')),
            success: new Style(foreground: Color::hex('50FA7B')),
            borderStyle: new Style(foreground: Color::hex('6272A4')),
            focusedBorderStyle: new Style(foreground: Color::hex('BD93F9'), attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('F1FA8C'), attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('F1FA8C'), attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('44475A')),
            cursorStyle: new Style(foreground: Color::hex('282A36'), background: Color::hex('BD93F9')),
            fillStyle: new Style(foreground: Color::hex('BD93F9')),
            emptyStyle: new Style(foreground: Color::hex('6272A4')),
            inputStyle: new Style(foreground: Color::hex('F8F8F2'), background: Color::hex('44475A')),
        );
    }

    public static function solarizedDark(): self
    {
        $background = Color::hex('002B36');

        return new self(
            base: new Style(foreground: Color::hex('839496'), background: $background),
            accent: new Style(foreground: Color::hex('268BD2'), background: $background),
            dim: new Style(foreground: Color::hex('586E75'), background: $background),
            error: new Style(foreground: Color::hex('DC322F'), background: $background),
            success: new Style(foreground: Color::hex('859900'), background: $background),
            borderStyle: new Style(foreground: Color::hex('586E75'), background: $background),
            focusedBorderStyle: new Style(foreground: Color::hex('268BD2'), background: $background, attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('073642')),
            cursorStyle: new Style(foreground: Color::hex('002B36'), background: Color::hex('268BD2')),
            fillStyle: new Style(foreground: Color::hex('268BD2'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('586E75'), background: $background),
            inputStyle: new Style(foreground: Color::hex('839496'), background: Color::hex('073642')),
            // base0 on the bar is only 4.11:1; base1 lifts it over WCAG AA.
            footerStyle: new Style(foreground: Color::hex('93A1A1'), background: Color::hex('073642')),
        );
    }

    public static function solarizedLight(): self
    {
        $background = Color::hex('FDF6E3');

        return new self(
            base: new Style(foreground: Color::hex('657B83'), background: $background),
            accent: new Style(foreground: Color::hex('268BD2'), background: $background),
            dim: new Style(foreground: Color::hex('93A1A1'), background: $background),
            error: new Style(foreground: Color::hex('DC322F'), background: $background),
            success: new Style(foreground: Color::hex('859900'), background: $background),
            borderStyle: new Style(foreground: Color::hex('93A1A1'), background: $background),
            focusedBorderStyle: new Style(foreground: Color::hex('268BD2'), background: $background, attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('EEE8D5')),
            cursorStyle: new Style(foreground: Color::hex('FDF6E3'), background: Color::hex('268BD2')),
            fillStyle: new Style(foreground: Color::hex('268BD2'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('93A1A1'), background: $background),
            inputStyle: new Style(foreground: Color::hex('657B83'), background: Color::hex('EEE8D5')),
            // base00 on the bar is only 3.64:1; base02 keeps hints legible.
            footerStyle: new Style(foreground: Color::hex('073642'), background: Color::hex('EEE8D5')),
        );
    }

    public static function rosePine(): self
    {
        $background = Color::hex('191724');

        return new self(
            base: new Style(foreground: Color::hex('E0DEF4'), background: $background),
            accent: new Style(foreground: Color::hex('C4A7E7'), background: $background),
            dim: new Style(foreground: Color::hex('6E6A86'), background: $background),
            error: new Style(foreground: Color::hex('EB6F92'), background: $background),
            success: new Style(foreground: Color::hex('9CCFD8'), background: $background),
            borderStyle: new Style(foreground: Color::hex('6E6A86'), background: $background),
            focusedBorderStyle: new Style(foreground: Color::hex('C4A7E7'), background: $background, attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('F6C177'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('F6C177'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('403D52')),
            cursorStyle: new Style(foreground: Color::hex('191724'), background: Color::hex('C4A7E7')),
            fillStyle: new Style(foreground: Color::hex('C4A7E7'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('6E6A86'), background: $background),
            inputStyle: new Style(foreground: Color::hex('E0DEF4'), background: Color::hex('1F1D2E')),
        );
    }

    public static function atomOneDark(): self
    {
        $background = Color::hex('282C34');

        return new self(
            base: new Style(foreground: Color::hex('ABB2BF'), background: $background),
            accent: new Style(foreground: Color::hex('61AFEF'), background: $background),
            dim: new Style(foreground: Color::hex('5C6370'), background: $background),
            error: new Style(foreground: Color::hex('E06C75'), background: $background),
            success: new Style(foreground: Color::hex('98C379'), background: $background),
            borderStyle: new Style(foreground: Color::hex('5C6370'), background: $background),
            focusedBorderStyle: new Style(foreground: Color::hex('C678DD'), background: $background, attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('E5C07B'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('E5C07B'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('3E4451')),
            cursorStyle: new Style(foreground: Color::hex('282C34'), background: Color::hex('C678DD')),
            fillStyle: new Style(foreground: Color::hex('56B6C2'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('5C6370'), background: $background),
            inputStyle: new Style(foreground: Color::hex('ABB2BF'), background: Color::hex('3E4451')),
        );
    }

    public static function gruvboxDark(): self
    {
        $background = Color::hex('282828');

        return new self(
            base: new Style(foreground: Color::hex('EBDBB2'), background: $background),
            accent: new Style(foreground: Color::hex('FABD2F'), background: $background),
            dim: new Style(foreground: Color::hex('928374'), background: $background),
            error: new Style(foreground: Color::hex('FB4934'), background: $background),
            success: new Style(foreground: Color::hex('B8BB26'), background: $background),
            borderStyle: new Style(foreground: Color::hex('928374'), background: $background),
            focusedBorderStyle: new Style(foreground: Color::hex('FE8019'), background: $background, attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('FABD2F'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('FABD2F'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('504945')),
            cursorStyle: new Style(foreground: Color::hex('282828'), background: Color::hex('FE8019')),
            fillStyle: new Style(foreground: Color::hex('8EC07C'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('928374'), background: $background),
            inputStyle: new Style(foreground: Color::hex('EBDBB2'), background: Color::hex('3C3836')),
            // Gruvbox puts its yellow on both accent and title, so warning has
            // to take the orange or it cannot be told apart from info.
            warning: new Style(foreground: Color::hex('FE8019'), background: $background),
        );
    }

    public static function gruvboxLight(): self
    {
        $background = Color::hex('FBF1C7');

        return new self(
            base: new Style(foreground: Color::hex('3C3836'), background: $background),
            accent: new Style(foreground: Color::hex('B57614'), background: $background),
            dim: new Style(foreground: Color::hex('928374'), background: $background),
            error: new Style(foreground: Color::hex('9D0006'), background: $background),
            success: new Style(foreground: Color::hex('79740E'), background: $background),
            borderStyle: new Style(foreground: Color::hex('928374'), background: $background),
            focusedBorderStyle: new Style(foreground: Color::hex('AF3A03'), background: $background, attributes: Attr::BOLD),
            titleStyle: new Style(foreground: Color::hex('B57614'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('B57614'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('D5C4A1')),
            cursorStyle: new Style(foreground: Color::hex('FBF1C7'), background: Color::hex('AF3A03')),
            fillStyle: new Style(foreground: Color::hex('427B58'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('928374'), background: $background),
            inputStyle: new Style(foreground: Color::hex('3C3836'), background: Color::hex('EBDBB2')),
            // The light selection background leaves the accent key hints at
            // 2.2:1, so this theme uses a dark bar to separate and stay legible.
            footerStyle: new Style(foreground: Color::hex('FBF1C7'), background: Color::hex('3C3836')),
            warning: new Style(foreground: Color::hex('AF3A03'), background: $background),
        );
    }
}
