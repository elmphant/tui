<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Prose;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Widget;

final readonly class Text implements Widget
{
    /**
     * @param list<Span> $spans
     */
    private function __construct(
        private array $spans = [],
    ) {
    }

    public static function new(string $content): self
    {
        return new self([new Span($content)]);
    }

    public static function styled(string $content, Style $style): self
    {
        return new self([Span::styled($content, $style)]);
    }

    public static function bold(string $content): self
    {
        return new self([(new Span($content))->bold()]);
    }

    public static function dim(string $content): self
    {
        return new self([Span::token($content, StyleToken::Dim)]);
    }

    public static function accent(string $content): self
    {
        return new self([Span::token($content, StyleToken::Accent)]);
    }

    public static function error(string $content): self
    {
        return new self([Span::token($content, StyleToken::Error)]);
    }

    public static function success(string $content): self
    {
        return new self([Span::token($content, StyleToken::Success)]);
    }

    public static function title(string $content): self
    {
        return new self([Span::token($content, StyleToken::Title)]);
    }

    public static function line(string|Span ...$spans): self
    {
        return new self(array_map(
            static fn (string|Span $span): Span => $span instanceof Span ? $span : new Span($span),
            array_values($spans),
        ));
    }

    public function sizing(): Sizing
    {
        $width = 0;

        foreach ($this->spans as $span) {
            $width += $span->width();
        }

        return Sizing::of(Constraint::exact($width), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->height < 1 || $area->width < 1) {
            return;
        }

        $x = $area->x;
        $rightEdge = $area->x + $area->width;

        foreach ($this->spans as $span) {
            if ($x >= $rightEdge) {
                break;
            }

            $resolved = $span->withResolvedToken($context->theme);
            $content = CharWidth::truncateToWidth($resolved->content, $rightEdge - $x);
            $x += $frame->putStyled($x, $area->y, $content, $resolved->style ?? $context->theme->base);
            if ($content !== $resolved->content) {
                break;
            }
        }
    }
}
