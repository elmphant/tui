<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Layout\ContentBasis;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentBasisTest extends TestCase
{
    #[Test]
    public function aDeclaredBasisIsTakenAsItStands(): void
    {
        $basis = ContentBasis::of(
            $this->measuring(9),
            Constraint::flex()->withBasis(4),
            Direction::Vertical,
            20,
        );

        self::assertSame(4, $basis);
    }

    #[Test]
    public function aChildDeclaringNoBasisIsMeasuredAgainstTheOtherAxis(): void
    {
        $basis = ContentBasis::of($this->measuring(9), Constraint::flex(), Direction::Vertical, 20);

        self::assertSame(9, $basis);
    }

    #[Test]
    public function acrossARowAChildIsNotMeasured(): void
    {
        $basis = ContentBasis::of($this->measuring(9), Constraint::min(2), Direction::Horizontal, 20);

        self::assertSame(2, $basis, 'measureHeight() answers rows, so a row would read a height as a width');
    }

    #[Test]
    public function acrossARowAChildIsAsWideAsItSaysItWants(): void
    {
        $basis = ContentBasis::of($this->wanting(5), Constraint::content(), Direction::Horizontal, 20);

        self::assertSame(5, $basis, 'a row has the width the child declares to go by, having no measurement');
    }

    #[Test]
    public function aChildPinnedToOneSizeIsNotMeasured(): void
    {
        $basis = ContentBasis::of($this->measuring(9), Constraint::exact(2), Direction::Vertical, 20);

        self::assertSame(2, $basis, 'There is nothing a measurement could change');
    }

    #[Test]
    public function withoutRoomAcrossThereIsNothingToMeasureAgainst(): void
    {
        $basis = ContentBasis::of($this->measuring(9), Constraint::min(2), Direction::Vertical, 0);

        self::assertSame(2, $basis);
    }

    /**
     * A widget that says how wide it wants to be and cannot measure itself.
     */
    private function wanting(int $columns): Widget
    {
        return new class($columns) implements Widget {
            public function __construct(private readonly int $columns)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact($this->columns), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function measuring(int $rows): Widget
    {
        return new class($rows) implements Widget, MeasureHeight {
            public function __construct(private readonly int $rows)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function measureHeight(int $width): int
            {
                return $this->rows;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
