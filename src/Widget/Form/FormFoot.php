<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Text\TextWrap;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;

/**
 * @internal the text a Form pins beside its submit button
 */
final readonly class FormFoot implements MeasureHeight, Widget
{
    public const BUTTON_HEIGHT = 3;

    /**
     * The text starts on the button's label row, not its top border.
     */
    private const LABEL_ROW = 1;
    private const GAP_TO_BUTTON = 2;

    public function __construct(
        private string $text,
        private int $buttonWidth,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::content());
    }

    public function measureHeight(int $width): int
    {
        return max(self::BUTTON_HEIGHT, \count($this->lines($width)) + self::LABEL_ROW);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($this->lines($area->width) as $offset => $line) {
            $frame->putStyled($area->x, $area->y + self::LABEL_ROW + $offset, $line, $context->theme->accent);
        }
    }

    /**
     * @return list<string>
     */
    private function lines(int $width): array
    {
        return $this->text === '' ? [] : TextWrap::wrap($this->text, max(1, $width - $this->buttonWidth - self::GAP_TO_BUTTON));
    }
}
