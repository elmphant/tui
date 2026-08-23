<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Structure\TabStripLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TabStripLayoutTest extends TestCase
{
    #[Test]
    public function theFirstTabStartsAtTheLeftEdgeOfTheArea(): void
    {
        $layout = TabStripLayout::across(['One', 'Two'], new Rect(0, 0, 40, 15));

        self::assertEquals(new Rect(0, 0, 7, 1), $layout->rectOf(0));
    }

    #[Test]
    public function aColumnInsideATabAnswersWithThatTabsIndex(): void
    {
        $layout = TabStripLayout::across(['One', 'Two'], new Rect(0, 0, 40, 15));

        self::assertSame(1, $layout->indexAt(7));
    }

    #[Test]
    public function aTabTooWideForWhatIsLeftOfTheStripGetsNoPlace(): void
    {
        $layout = TabStripLayout::across(['One', 'Two'], new Rect(0, 0, 13, 5));

        self::assertNull($layout->rectOf(1), 'The second tab needs seven columns and six are left');
        self::assertNull($layout->indexAt(7), 'So no column belongs to it');
    }

    #[Test]
    public function everyColumnOfATabAnswersWithThatTabsIndex(): void
    {
        foreach (self::labelSets() as $labels) {
            foreach ([12, 20, 33, 60] as $stripWidth) {
                $layout = TabStripLayout::across($labels, new Rect(2, 1, $stripWidth, 5));

                foreach (array_keys($labels) as $index) {
                    $cell = $layout->rectOf($index);
                    if ($cell === null) {
                        continue;
                    }

                    for ($column = $cell->x; $column < $cell->right(); ++$column) {
                        self::assertSame(
                            $index,
                            $layout->indexAt($column),
                            sprintf('Column %d of a %d wide strip', $column, $stripWidth),
                        );
                    }
                }
            }
        }
    }

    #[Test]
    public function aColumnBesideEveryTabBelongsToNone(): void
    {
        foreach (self::labelSets() as $labels) {
            foreach ([12, 20, 33, 60] as $stripWidth) {
                $area = new Rect(2, 1, $stripWidth, 5);
                $layout = TabStripLayout::across($labels, $area);

                self::assertNull($layout->indexAt($area->x - 1), 'Left of the strip');

                for ($column = $layout->columnAfterLastTab(); $column < $area->right(); ++$column) {
                    self::assertNull(
                        $layout->indexAt($column),
                        sprintf('Column %d of a %d wide strip is past the last tab', $column, $stripWidth),
                    );
                }
            }
        }
    }

    /**
     * @return list<list<string>>
     */
    private static function labelSets(): array
    {
        return [
            ['A'],
            ['One', 'Two'],
            ['X', 'YY', 'ZZZ'],
            ["\u{00e4}\u{00f6}\u{00fc}", 'Two', 'Three'],
        ];
    }
}
