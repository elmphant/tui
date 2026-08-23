<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Table\Column;
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
    public function widthReturnsSetValue(): void
    {
        $width = Constraint::exact(10);
        $column = Column::new('ID', $width);
        self::assertSame($width, $column->width());
    }

    #[Test]
    public function flexColumn(): void
    {
        $column = Column::new('Name', Constraint::flex(3));
        self::assertSame(3, $column->width()->flex);
    }

    #[Test]
    public function exactColumn(): void
    {
        $column = Column::new('ID', Constraint::exact(5));
        self::assertSame(5, $column->width()->min);
        self::assertSame(5, $column->width()->max);
    }
}
