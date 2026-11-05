<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Widget;

final readonly class StatusBar implements Widget
{
    private const ELLIPSIS = '…';
    private const HINT_GAP = 2;

    /**
     * @param list<array{string, string}> $hints
     * @param list<array{string, string}> $pinned
     */
    private function __construct(
        private string $content,
        private array $hints,
        private array $pinned = [],
    ) {
    }

    public static function new(string $content): self
    {
        return new self($content, []);
    }

    /**
     * @param array<string, string> $hints
     */
    public static function forHints(array $hints): self
    {
        return new self('', self::pairsOf($hints));
    }

    /**
     * Hints drawn after the others, kept when the others are cut.
     *
     * @param array<string, string> $hints
     */
    public function withPinned(array $hints): self
    {
        return new self(content: $this->content, hints: $this->hints, pinned: self::pairsOf($hints));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex()->withBasis($this->inkWidth()), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;
        $barStyle = $theme->footerStyle;
        $frame->fillRect($area->x, $area->y, $area->width, 1, $barStyle->foregroundValue(), $barStyle->backgroundValue(), $barStyle->attributes);

        if ($this->hints !== [] || $this->pinned !== []) {
            $this->renderHints($frame, $area, $barStyle, $theme);

            return;
        }

        $frame->putStyled($area->x, $area->y, CharWidth::truncateToWidth($this->content, $area->width), $barStyle);
    }

    /**
     * Exclude the last hint's trailing gap from content width; the bar still fills the row.
     */
    /**
     * PHP turns a label like "1" into an int key, so it is cast back here.
     *
     * @param array<array-key, string> $hints
     *
     * @return list<array{string, string}>
     */
    private static function pairsOf(array $hints): array
    {
        $pairs = [];
        foreach ($hints as $key => $description) {
            $pairs[] = [(string)$key, $description];
        }

        return $pairs;
    }

    /**
     * Exclude the last hint's trailing gap from content width; the bar still fills the row.
     */
    private function inkWidth(): int
    {
        if ($this->hints === [] && $this->pinned === []) {
            return CharWidth::stringWidth($this->content);
        }

        return self::widthOf([...$this->hints, ...$this->pinned]) - self::HINT_GAP;
    }

    /**
     * @param list<array{string, string}> $hints
     */
    private static function widthOf(array $hints): int
    {
        $width = 0;
        foreach ($hints as [$key, $description]) {
            $width += CharWidth::stringWidth(' ' . $key . '  ' . $description . '  ');
        }

        return $width;
    }

    private function renderHints(Frame $frame, Rect $area, Style $barStyle, Theme $theme): void
    {
        $keyStyle = $theme->accent->withBackground($barStyle->background ?? Color::ansi(AnsiColor::Black))->bold();
        $x = $area->x;
        $rightEdge = $area->x + $area->width;
        $last = \count($this->hints) - 1;
        $ellipsis = CharWidth::stringWidth(self::ELLIPSIS);
        $reserved = $this->pinned === [] ? 0 : self::widthOf($this->pinned);

        foreach ($this->hints as $index => [$key, $description]) {
            $width = self::widthOf([[$key, $description]]);
            $fits = $index === $last
                ? $x + $width - self::HINT_GAP + $reserved <= $rightEdge
                : $x + $width + $ellipsis + $reserved <= $rightEdge;

            if (!$fits) {
                if ($x + $ellipsis + $reserved <= $rightEdge) {
                    $x += $frame->putStyled($x, $area->y, self::ELLIPSIS, $barStyle) + ($this->pinned === [] ? 0 : self::HINT_GAP);
                }

                break;
            }

            $x = $this->drawHint($frame, $x, $area->y, $rightEdge, $key, $description, $keyStyle, $barStyle);
        }

        foreach ($this->pinned as [$key, $description]) {
            $x = $this->drawHint($frame, $x, $area->y, $rightEdge, $key, $description, $keyStyle, $barStyle);
        }
    }

    private function drawHint(Frame $frame, int $x, int $y, int $rightEdge, string $key, string $description, Style $keyStyle, Style $barStyle): int
    {
        $x += $frame->putStyled($x, $y, ' ' . $key . ' ', $keyStyle);

        return $x + $frame->putStyled($x, $y, CharWidth::truncateToWidth(' ' . $description . '  ', max(0, $rightEdge - $x)), $barStyle);
    }
}
