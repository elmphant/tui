<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Widget;

final readonly class Card implements Widget
{
    use Cloneable;

    private function __construct(
        private string $title = '',
        private string $body = '',
        private ?Style $borderStyle = null,
        private ?Style $titleStyle = null,
        private ?Style $bodyStyle = null,
        private BorderStyle $border = BorderStyle::Rounded,
        private int $height = 4,
        private bool $dimmed = false,
    ) {
    }

    public static function new(string $title = '', string $body = ''): self
    {
        return new self(title: $title, body: $body);
    }

    public function withTitle(string $title): self
    {
        return $this->cloneWith(title: $title);
    }

    public function withBody(string $body): self
    {
        return $this->cloneWith(body: $body);
    }

    public function withBorderStyle(Style $style): self
    {
        return $this->cloneWith(borderStyle: $style);
    }

    public function withTitleStyle(Style $style): self
    {
        return $this->cloneWith(titleStyle: $style);
    }

    public function withBodyStyle(Style $style): self
    {
        return $this->cloneWith(bodyStyle: $style);
    }

    public function withBorder(BorderStyle $border): self
    {
        return $this->cloneWith(border: $border);
    }

    public function withHeight(int $height): self
    {
        return $this->cloneWith(height: $height);
    }

    public function withDimmed(bool $dimmed = true): self
    {
        return $this->cloneWith(dimmed: $dimmed);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact($this->height));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $inset = (new Inset(border: true, padding: Spacing::horizontal(1)))->fitting($area);
        $inner = $inset->inner($area);
        if ($inner->width < 1 || $inner->height < 1) {
            return;
        }

        $theme = $context->theme;
        $borderStyle = $this->borderStyle ?? $theme->borderStyle;
        $titleStyle = $this->titleStyle ?? $theme->base->bold();
        $bodyStyle = $this->bodyStyle ?? $theme->dim;

        if ($this->dimmed) {
            $borderStyle = $borderStyle->faint();
            $titleStyle = $titleStyle->faint();
            $bodyStyle = $bodyStyle->faint();
        }

        if ($inset->border) {
            Block::new()->withBorder($this->border)->withBorderStyle($borderStyle)->render($frame, $area);
        }

        // Clear inner area so stale content behind the card (e.g. column borders) doesn't bleed through.
        $cleared = $inset->border ? $area->inner() : $area;
        $frame->fillRect(
            $cleared->x,
            $cleared->y,
            $cleared->width,
            $cleared->height,
            $borderStyle->foregroundValue(),
            $borderStyle->backgroundValue(),
            0,
            ' ',
        );

        if ($this->title !== '') {
            $frame->putString(
                $inner->x,
                $inner->y,
                CharWidth::truncateToWidth($this->title, $inner->width),
                $titleStyle->foregroundValue(),
                $titleStyle->backgroundValue(),
                $titleStyle->attributes,
            );
        }

        if ($this->body !== '' && $inner->height >= 2) {
            $frame->putString(
                $inner->x,
                $inner->y + 1,
                CharWidth::truncateToWidth($this->body, $inner->width),
                $bodyStyle->foregroundValue(),
                $bodyStyle->backgroundValue(),
                $bodyStyle->attributes,
            );
        }
    }
}
