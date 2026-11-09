<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\StaticTable;
use Elmphant\Tui\Widget\Table\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ATableCellKeepsItsIndentTest extends TestCase
{
    #[Test]
    public function leadingSpacesIndentACellThatFits(): void
    {
        $screen = self::drawn([Row::new('✓ Set up'), Row::new('  Deploy')], 20);

        self::assertSame(2, $screen->findOrFail('Deploy')['column']);
    }

    #[Test]
    public function everyLineOfAWrappedCellKeepsTheIndent(): void
    {
        $screen = self::drawn([Row::new('  Deploy to staging')], 10);

        self::assertSame(['row' => 1, 'column' => 2], $screen->findOrFail('Deploy'));
        self::assertSame(['row' => 2, 'column' => 2], $screen->findOrFail('to'));
        self::assertSame(['row' => 3, 'column' => 2], $screen->findOrFail('staging'));
    }

    #[Test]
    public function everyLineOfAWrappedCellOfSeveralSpansKeepsTheIndentAndTheColours(): void
    {
        $theme = Theme::dark();
        $screen = self::drawn([Row::new(new Line([Span::raw('  Deploy to '), Span::token('staging', StyleToken::Error)]))], 10);
        $staging = $screen->findOrFail('staging');

        self::assertSame(['row' => 3, 'column' => 2], $staging);
        self::assertEquals($theme->error->foreground, $screen->styleAt($staging['column'], $staging['row'])->foreground);
    }

    #[Test]
    public function aStaticTableCellKeepsItsIndentToo(): void
    {
        $frame = new Frame(30, 6);
        StaticTable::new(['Task'], [['✓ Set up'], ['  Deploy']])->render($frame, Rect::fromSize(30, 6), new RenderContext(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame($screen->findOrFail('✓')['column'] + 2, $screen->findOrFail('Deploy')['column']);
    }

    /**
     * @param list<Row> $rows
     */
    private static function drawn(array $rows, int $width): ScreenBuffer
    {
        $frame = new Frame($width, 6);
        Table::new([Column::new('Task', Constraint::flex())], $rows)
            ->render($frame, Rect::fromSize($width, 6), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }
}
