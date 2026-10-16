<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterBarTest extends TestCase
{
    #[Test]
    public function aWideSummaryEndsAtTheRightEdge(): void
    {
        self::assertSame('Filt   全部', $this->drawn(11, '全部'));
    }

    #[Test]
    public function withoutASummaryTheFieldTakesTheWholeRow(): void
    {
        self::assertSame('Filter…', $this->drawn(7, null));
    }

    #[Test]
    public function aSummaryAsWideAsTheRowIsStillShown(): void
    {
        self::assertSame('1 of 3', $this->drawn(6, '1 of 3'));
    }

    #[Test]
    public function aRowTooShortForTheGapLeavesTheBodyNoRows(): void
    {
        self::assertSame(0, FilterBar::bodyArea(Rect::fromSize(10, 1), gap: 1)->height);
    }

    private function drawn(int $width, ?string $summary): string
    {
        $frame = new Frame($width, 1);
        FilterBar::render($frame, Rect::fromSize($width, 1), RenderContext::unfocused(Theme::dark()), FilterBar::field(''), $summary);

        return rtrim(ScreenBuffer::fromFrame($frame)->lineAt(0));
    }
}
