<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\ColorProfile;

final readonly class Theme
{
    public Style $footerStyle;

    /**
     * Defaults to the title amber, which is every bundled palette's warning hue.
     */
    public Style $warning;

    public Style $info;

    private ?Style $footerOverride;

    private ?Style $warningOverride;

    private ?Style $infoOverride;

    private function __construct(
        public Style $base,
        public Style $accent,
        public Style $dim,
        public Style $error,
        public Style $success,
        public Style $borderStyle,
        public Style $focusedBorderStyle,
        public Style $title,
        public Style $headerStyle,
        public Style $selectedStyle,
        public Style $cursorStyle,
        public Style $fillStyle,
        public Style $emptyStyle,
        public Style $inputStyle,
        /**
         * The palette's assumed background, used for contrast; it does not paint the terminal.
         */
        public ?Color $canvas = null,
        ?Style $footerStyle = null,
        ?Style $warning = null,
        ?Style $info = null,
        public BorderType $borderType = BorderType::Rounded,
        // Unicode has no heavy rounded corners; focus is carried by colour + bold.
        public BorderType $focusedBorderType = BorderType::Rounded,
        public bool $raisedButtons = false,
    ) {
        $this->footerOverride = $footerStyle;
        $this->warningOverride = $warning;
        $this->infoOverride = $info;
        $this->footerStyle = $footerStyle ?? new Style(
            foreground: $base->foreground ?? Color::ansi(AnsiColor::White),
            background: $selectedStyle->background ?? Color::ansi(AnsiColor::Black),
        );
        $this->warning = $warning ?? $title->withAttributes($title->attributes & ~Attr::BOLD);
        $this->info = $info ?? $accent;
    }

    public function withBase(Style $base): self
    {
        return $this->rebuilt(base: $base);
    }

    public function withAccent(Style $accent): self
    {
        return $this->rebuilt(accent: $accent);
    }

    public function withDim(Style $dim): self
    {
        return $this->rebuilt(dim: $dim);
    }

    public function withError(Style $error): self
    {
        return $this->rebuilt(error: $error);
    }

    public function withSuccess(Style $success): self
    {
        return $this->rebuilt(success: $success);
    }

    public function withWarning(Style $warning): self
    {
        return $this->rebuilt(warning: $warning);
    }

    public function withInfo(Style $info): self
    {
        return $this->rebuilt(info: $info);
    }

    public function withBorderStyle(Style $style): self
    {
        return $this->rebuilt(borderStyle: $style);
    }

    public function withFocusedBorderStyle(Style $style): self
    {
        return $this->rebuilt(focusedBorderStyle: $style);
    }

    public function withTitle(Style $style): self
    {
        return $this->rebuilt(title: $style);
    }

    public function withHeaderStyle(Style $style): self
    {
        return $this->rebuilt(headerStyle: $style);
    }

    public function withSelectedStyle(Style $style): self
    {
        return $this->rebuilt(selectedStyle: $style);
    }

    public function withCursorStyle(Style $style): self
    {
        return $this->rebuilt(cursorStyle: $style);
    }

    public function withFillStyle(Style $style): self
    {
        return $this->rebuilt(fillStyle: $style);
    }

    public function withEmptyStyle(Style $style): self
    {
        return $this->rebuilt(emptyStyle: $style);
    }

    public function withInputStyle(Style $style): self
    {
        return $this->rebuilt(inputStyle: $style);
    }

    public function withFooterStyle(Style $style): self
    {
        return $this->rebuilt(footerStyle: $style);
    }

    /**
     * Apply a page background while preserving the backgrounds of filled rows.
     */
    public function withCanvas(Color $canvas): self
    {
        return $this->rebuilt(
            base: $this->base->withBackground($canvas),
            accent: $this->accent->withBackground($canvas),
            dim: $this->dim->withBackground($canvas),
            error: $this->error->withBackground($canvas),
            success: $this->success->withBackground($canvas),
            warning: $this->warningOverride?->withBackground($canvas),
            info: $this->infoOverride?->withBackground($canvas),
            borderStyle: $this->borderStyle->withBackground($canvas),
            focusedBorderStyle: $this->focusedBorderStyle->withBackground($canvas),
            title: $this->title->withBackground($canvas),
            headerStyle: $this->headerStyle->withBackground($canvas),
            fillStyle: $this->fillStyle->withBackground($canvas),
            emptyStyle: $this->emptyStyle->withBackground($canvas),
            canvas: $canvas,
        );
    }

    public function withBorderType(BorderType $type): self
    {
        return $this->rebuilt(borderType: $type);
    }

    public function withFocusedBorderType(BorderType $type): self
    {
        return $this->rebuilt(focusedBorderType: $type);
    }

    public function withRaisedButtons(bool $raised = true): self
    {
        return $this->rebuilt(raisedButtons: $raised);
    }

    /**
     * A terminal without colour drops every fill, so a filled row (cursor,
     * selection, input, bars) is drawn in reverse video instead.
     */
    public function shownIn(ColorProfile $profile): self
    {
        if (ColorProfile::Ascii !== $profile) {
            return $this;
        }

        return new self(
            base: $this->withoutColour($this->base),
            accent: $this->withoutColour($this->accent),
            dim: $this->withoutColour($this->dim),
            error: $this->withoutColour($this->error),
            success: $this->withoutColour($this->success),
            borderStyle: $this->withoutColour($this->borderStyle),
            focusedBorderStyle: $this->withoutColour($this->focusedBorderStyle),
            title: $this->withoutColour($this->title),
            headerStyle: $this->withoutColour($this->headerStyle),
            selectedStyle: $this->withoutColour($this->selectedStyle),
            cursorStyle: $this->withoutColour($this->cursorStyle),
            fillStyle: $this->withoutColour($this->fillStyle),
            emptyStyle: $this->withoutColour($this->emptyStyle),
            inputStyle: $this->withoutColour($this->inputStyle),
            footerStyle: $this->withoutColour($this->footerStyle),
            warning: $this->withoutColour($this->warning),
            info: $this->withoutColour($this->info),
            borderType: $this->borderType,
            focusedBorderType: $this->focusedBorderType,
            raisedButtons: $this->raisedButtons,
        );
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
            title: new Style(foreground: Color::hex('E5C07B'), attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('E5C07B'), attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('3E4451')),
            cursorStyle: new Style(foreground: Color::ansi(AnsiColor::Black), background: Color::hex('61AFEF')),
            fillStyle: new Style(foreground: Color::hex('61AFEF')),
            emptyStyle: new Style(foreground: Color::hex('5C6370')),
            inputStyle: new Style(foreground: Color::hex('ABB2BF'), background: Color::hex('3E4451')),
            canvas: Color::hex('282C34'),
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
            title: new Style(foreground: Color::hex('986801'), attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('986801'), attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('DEDEDF')),
            // A darker blue than the accent: white on the accent itself is 3.88:1.
            cursorStyle: new Style(foreground: Color::hex('FAFAFA'), background: Color::hex('3563D2')),
            fillStyle: new Style(foreground: Color::hex('4078F2')),
            emptyStyle: new Style(foreground: Color::hex('D3D3D3')),
            inputStyle: new Style(foreground: Color::hex('383A42'), background: Color::hex('DEDEDF')),
            canvas: Color::hex('FAFAFA'),
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
            title: new Style(foreground: Color::hex('F1FA8C'), attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('F1FA8C'), attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('44475A')),
            cursorStyle: new Style(foreground: Color::hex('282A36'), background: Color::hex('BD93F9')),
            fillStyle: new Style(foreground: Color::hex('BD93F9')),
            emptyStyle: new Style(foreground: Color::hex('6272A4')),
            inputStyle: new Style(foreground: Color::hex('F8F8F2'), background: Color::hex('44475A')),
            canvas: Color::hex('282A36'),
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
            title: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            // Raise the bar's contrast above base02's 1.15:1 against base03.
            selectedStyle: new Style(foreground: Color::hex('9EABAB'), background: Color::hex('0F3C48')),
            // A lighter blue than the accent: base03 on the accent itself is 4.08:1.
            cursorStyle: new Style(foreground: Color::hex('002B36'), background: Color::hex('3AA0E0')),
            fillStyle: new Style(foreground: Color::hex('268BD2'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('586E75'), background: $background),
            inputStyle: new Style(foreground: Color::hex('9EABAB'), background: Color::hex('0F3C48')),
            canvas: $background,
            footerStyle: new Style(foreground: Color::hex('9EABAB'), background: Color::hex('0F3C48')),
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
            title: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('B58900'), background: $background, attributes: Attr::BOLD),
            // base02 on base2 throughout: base00 on that bar is only 3.64:1.
            selectedStyle: new Style(foreground: Color::hex('073642'), background: Color::hex('DFDAC8')),
            // A darker blue than the accent: base3 on the accent itself is 3.41:1.
            cursorStyle: new Style(foreground: Color::hex('FDF6E3'), background: Color::hex('1E73AE')),
            fillStyle: new Style(foreground: Color::hex('268BD2'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('93A1A1'), background: $background),
            inputStyle: new Style(foreground: Color::hex('073642'), background: Color::hex('DFDAC8')),
            canvas: $background,
            // Use a dark bottom bar because accent key hints have only 2.6:1 contrast on a light bar.
            footerStyle: new Style(foreground: Color::hex('FDF6E3'), background: Color::hex('073642')),
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
            title: new Style(foreground: Color::hex('F6C177'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('F6C177'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('403D52')),
            cursorStyle: new Style(foreground: Color::hex('191724'), background: Color::hex('C4A7E7')),
            fillStyle: new Style(foreground: Color::hex('C4A7E7'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('6E6A86'), background: $background),
            // Increase contrast: Overlay is only 1.07:1 against Base.
            inputStyle: new Style(foreground: Color::hex('E0DEF4'), background: Color::hex('2D2B3B')),
            canvas: $background,
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
            title: new Style(foreground: Color::hex('E5C07B'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('E5C07B'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('3E4451')),
            cursorStyle: new Style(foreground: Color::hex('282C34'), background: Color::hex('C678DD')),
            fillStyle: new Style(foreground: Color::hex('56B6C2'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('5C6370'), background: $background),
            inputStyle: new Style(foreground: Color::hex('ABB2BF'), background: Color::hex('3E4451')),
            canvas: $background,
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
            title: new Style(foreground: Color::hex('FABD2F'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('FABD2F'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('504945')),
            cursorStyle: new Style(foreground: Color::hex('282828'), background: Color::hex('FE8019')),
            fillStyle: new Style(foreground: Color::hex('8EC07C'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('928374'), background: $background),
            inputStyle: new Style(foreground: Color::hex('EBDBB2'), background: Color::hex('3C3836')),
            canvas: $background,
            // Use orange for warnings because Gruvbox already uses yellow for both accent and title.
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
            title: new Style(foreground: Color::hex('B57614'), background: $background, attributes: Attr::BOLD),
            headerStyle: new Style(foreground: Color::hex('B57614'), background: $background, attributes: Attr::BOLD),
            selectedStyle: new Style(background: Color::hex('D5C4A1')),
            cursorStyle: new Style(foreground: Color::hex('FBF1C7'), background: Color::hex('AF3A03')),
            fillStyle: new Style(foreground: Color::hex('427B58'), background: $background),
            emptyStyle: new Style(foreground: Color::hex('928374'), background: $background),
            inputStyle: new Style(foreground: Color::hex('3C3836'), background: Color::hex('E4D4AC')),
            canvas: $background,
            // Use a dark bar because accent key hints have only 2.2:1 contrast on the light selection background.
            footerStyle: new Style(foreground: Color::hex('FBF1C7'), background: Color::hex('3C3836')),
            warning: new Style(foreground: Color::hex('AF3A03'), background: $background),
        );
    }

    /**
     * Pass on the overrides, never the derived styles, or a derived style stops following its source.
     */
    private function rebuilt(
        ?Style $base = null,
        ?Style $accent = null,
        ?Style $dim = null,
        ?Style $error = null,
        ?Style $success = null,
        ?Style $borderStyle = null,
        ?Style $focusedBorderStyle = null,
        ?Style $title = null,
        ?Style $headerStyle = null,
        ?Style $selectedStyle = null,
        ?Style $cursorStyle = null,
        ?Style $fillStyle = null,
        ?Style $emptyStyle = null,
        ?Style $inputStyle = null,
        ?Color $canvas = null,
        ?Style $footerStyle = null,
        ?Style $warning = null,
        ?Style $info = null,
        ?BorderType $borderType = null,
        ?BorderType $focusedBorderType = null,
        ?bool $raisedButtons = null,
    ): self {
        return new self(
            base: $base ?? $this->base,
            accent: $accent ?? $this->accent,
            dim: $dim ?? $this->dim,
            error: $error ?? $this->error,
            success: $success ?? $this->success,
            borderStyle: $borderStyle ?? $this->borderStyle,
            focusedBorderStyle: $focusedBorderStyle ?? $this->focusedBorderStyle,
            title: $title ?? $this->title,
            headerStyle: $headerStyle ?? $this->headerStyle,
            selectedStyle: $selectedStyle ?? $this->selectedStyle,
            cursorStyle: $cursorStyle ?? $this->cursorStyle,
            fillStyle: $fillStyle ?? $this->fillStyle,
            emptyStyle: $emptyStyle ?? $this->emptyStyle,
            inputStyle: $inputStyle ?? $this->inputStyle,
            canvas: $canvas ?? $this->canvas,
            footerStyle: $footerStyle ?? $this->footerOverride,
            warning: $warning ?? $this->warningOverride,
            info: $info ?? $this->infoOverride,
            borderType: $borderType ?? $this->borderType,
            focusedBorderType: $focusedBorderType ?? $this->focusedBorderType,
            raisedButtons: $raisedButtons ?? $this->raisedButtons,
        );
    }

    private function withoutColour(Style $style): Style
    {
        $filled = null !== $style->background && $style->background->value !== $this->canvas?->value;

        return new Style(attributes: $style->attributes | ($filled ? Attr::REVERSE : 0));
    }
}
