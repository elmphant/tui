<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Column;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    #[Test]
    public function titleReturnsSetValue(): void
    {
        $column = Column::new('Name', Constraint::flex(2));
        self::assertSame('Name', $column->title());
    }

    #[Test]
    public function constraintReturnsSetValue(): void
    {
        $constraint = Constraint::exact(10);
        $column = Column::new('ID', $constraint);
        self::assertSame($constraint, $column->constraint());
    }

    #[Test]
    public function flexColumn(): void
    {
        $column = Column::new('Name', Constraint::flex(3));
        self::assertSame(3, $column->constraint()->flex);
    }

    #[Test]
    public function exactColumn(): void
    {
        $column = Column::new('ID', Constraint::exact(5));
        self::assertSame(5, $column->constraint()->min);
        self::assertSame(5, $column->constraint()->max);
    }
}
