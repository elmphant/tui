<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;

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
    public static function hints(array $hints): self
    {
        $pairs = [];
        foreach ($hints as $key => $description) {
            $pairs[] = [$key, $description];
        }

        return new self('', $pairs);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
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
