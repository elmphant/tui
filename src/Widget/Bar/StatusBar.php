<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Widget;

final readonly class StatusBar implements Widget
{
    /**
     * @param list<array{string, string}> $hints
     */
    private function __construct(
        private string $content,
        private array $hints,
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
        $pairs = [];
        foreach ($hints as $key => $description) {
            $pairs[] = [$key, $description];
        }

        return new self('', $pairs);
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

        if ($this->hints !== []) {
            $this->renderHints($frame, $area, $barStyle, $theme);

            return;
        }

        $frame->putStyled($area->x, $area->y, CharWidth::truncateToWidth($this->content, $area->width), $barStyle);
    }

    /**
     * The bar paints its style across the whole row, so its width is the ink:
     * the last hint's trailing pad separates it from a next one that is not there.
     */
    private function inkWidth(): int
    {
        if ($this->hints === []) {
            return CharWidth::stringWidth($this->content);
        }

        $width = 0;

        foreach ($this->hints as [$key, $description]) {
            $width += CharWidth::stringWidth(' ' . $key . '  ' . $description . '  ');
        }

        return $width - 2;
    }

    private function renderHints(Frame $frame, Rect $area, Style $barStyle, Theme $theme): void
    {
        $keyStyle = $theme->accent->background($barStyle->background ?? Color::ansi(0))->bold();
        $x = $area->x;
        $rightEdge = $area->x + $area->width;

        foreach ($this->hints as [$key, $description]) {
            $keyText = ' ' . $key . ' ';
            $descText = ' ' . $description . '  ';
            $remainingWidth = max(0, $rightEdge - $x);
            $x += $frame->putStyled($x, $area->y, CharWidth::truncateToWidth($keyText, $remainingWidth), $keyStyle);
            $remainingWidth = max(0, $rightEdge - $x);
            $x += $frame->putStyled($x, $area->y, CharWidth::truncateToWidth($descText, $remainingWidth), $barStyle);
        }
    }
}
