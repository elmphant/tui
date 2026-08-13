<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Sizing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SizingTest extends TestCase
{
    #[Test]
    public function itHoldsAnAnswerForEachAxis(): void
    {
        $sizing = Sizing::of(columns: Constraint::exact(9), rows: Constraint::exact(1));

        self::assertSame(9, $sizing->columns->max);
        self::assertSame(1, $sizing->rows->max);
    }

    #[Test]
    public function itAnswersForWhicheverAxisIsBeingShared(): void
    {
        $sizing = Sizing::of(columns: Constraint::exact(9), rows: Constraint::exact(1));

        self::assertSame(9, $sizing->on(Direction::Horizontal)->max);
        self::assertSame(1, $sizing->on(Direction::Vertical)->max);
    }
}
