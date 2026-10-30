<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Overflow;
use Elmphant\Tui\Widget\Table\StaticTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaticTableTest extends TestCase
{
    #[Test]
    public function choosingAnOverflowPolicyKeepsTheMinimumWidth(): void
    {
        $stacking = StaticTable::new(['keepmin'], [['a']])->withMinWidth(30);

        self::assertSame(30, $stacking->withOverflow(Overflow::Wrap)->drawnWidth(60));
    }

    #[Test]
    public function aTableDrawsItsOwnMinimumWidthWhateverWasDrawnBefore(): void
    {
        $headers = ['cachekey'];
        $rows = [['a']];

        StaticTable::new($headers, $rows)->drawnWidth(60);

        self::assertSame(30, StaticTable::new($headers, $rows)->withMinWidth(30)->drawnWidth(60));
    }

    #[Test]
    public function aTableRebuiltFromADrawnOneDrawsItsOwnMinimumWidth(): void
    {
        $drawn = StaticTable::new(['memo'], [['a']])->withMinWidth(30);
        $drawn->drawnWidth(60);

        self::assertSame(50, $drawn->withMinWidth(50)->drawnWidth(60));
    }

    /**
     * Measure columns from all rows but draw only the filtered subset.
     */
    #[Test]
    public function sizingRowsGivenAfterADrawWidenTheColumnsForRowsItDoesNotDraw(): void
    {
        $drawn = StaticTable::new(['A'], [['x']]);
        $narrow = $drawn->drawnWidth(60);

        self::assertGreaterThan($narrow, $drawn->withWidthsFrom([['a far longer value']])->drawnWidth(60));
    }

    /**
     * Inspect cell positions to check widths, padding, dividers and wrapping together.
     */
    #[Test]
    public function drawsEveryColumnToTheWidthItWorkedOut(): void
    {
        $squeezed = <<<'TABLE'
            ┌────────────────────┬─────────────────┐
            │ Identifier         │ Title           │
            ├────────────────────┼─────────────────┤
            │ setup_userSettings │ migrate the     │
            │                    │ user settings   │
            └────────────────────┴─────────────────┘
            TABLE;

        self::assertSame($squeezed, $this->drawnRows($this->table(), 40, 8));

        $roomy = <<<'TABLE'
            ┌────────────────────┬───────────────────────────┐
            │ Identifier         │ Title                     │
            ├────────────────────┼───────────────────────────┤
            │ setup_userSettings │ migrate the user settings │
            └────────────────────┴───────────────────────────┘
            TABLE;

        self::assertSame($roomy, $this->drawnRows($this->table(), 60, 8), 'Given room, the columns take what they need and no more');
    }

    #[Test]
    public function theWidthLeftOverIsSharedByWhatEachColumnStillWantsAndTheRestGoesToTheHungriest(): void
    {
        $table = StaticTable::new(['A', 'B', 'C'], [['aaaa bbbb cccc', 'dd ee ff gg hh ii', 'x']]);

        $expected = <<<'TABLE'
            ┌─────────┬────────────┬──────┐
            │ A       │ B          │ C    │
            ├─────────┼────────────┼──────┤
            │ aaaa    │ dd ee ff   │ x    │
            │ bbbb    │ gg hh ii   │      │
            │ cccc    │            │      │
            └─────────┴────────────┴──────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 31, 9));
    }

    #[Test]
    public function aViewportTallerThanTheFirstWindowShowsEveryRowAfterASmallerOneWasDrawn(): void
    {
        $table = StaticTable::new(['Name'], array_map(static fn (int $row): array => ["row $row"], range(1, 100)));
        $this->render($table, 20, 5);

        $lines = explode("\n", $this->drawnRows($table, 20, 110));

        self::assertCount(104, $lines);
        self::assertSame(['│ row 100 │', '└─────────┘'], array_slice($lines, -2));
    }

    #[Test]
    public function aWrappingTableIsAsTallAsAllItsRowsAfterAWindowOfItWasDrawn(): void
    {
        $table = StaticTable::new(['Name'], array_fill(0, 100, ['aaaa window bbbb']));
        $this->render($table, 10, 5);

        self::assertSame(304, $table->measureHeight(10));
    }

    #[Test]
    public function aTableWithNoColumnsDrawsAnEmptyFrame(): void
    {
        self::assertSame("┌┐\n└┘", $this->drawnRows(StaticTable::new([], []), 10, 4));
    }

    #[Test]
    public function aTableHasAColumnForEveryHeaderAndEveryCellAndAnEmptyOneTakesNoWidth(): void
    {
        $table = StaticTable::new(['', 'B', ''], [['x'], ['y', 'z']]);

        $expected = <<<'TABLE'
            ┌───┬───┬──┐
            │   │ B │  │
            ├───┼───┼──┤
            │ x │   │  │
            │ y │ z │  │
            └───┴───┴──┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 30, 8));
    }

    #[Test]
    public function theRowsATableIsSizedForCanHaveMoreColumnsThanTheRowsItDraws(): void
    {
        $table = StaticTable::new([], [['a']])->withWidthsFrom([['a'], ['b', 'cc']]);

        $expected = <<<'TABLE'
            ┌───┬────┐
            │ a │    │
            └───┴────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 30, 8));
    }

    #[Test]
    public function anEvenShareGivesTheOddCellToTheLastColumn(): void
    {
        $table = StaticTable::new(['A', 'B'], [['abcdefghij', 'abcdefghij']])->withOverflow(Overflow::Wrap);

        $expected = <<<'TABLE'
            ┌────────┬─────────┐
            │ A      │ B       │
            ├────────┼─────────┤
            │ abcdef │ abcdefg │
            │ ghij   │ hij     │
            └────────┴─────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 20, 8));
    }

    /**
     * Size filtered tables from all rows so headers and columns do not move when the query changes.
     */
    #[Test]
    public function headersAreWidenedToColumnsSizedFromRowsThatAreNotDrawn(): void
    {
        $sizedForAll = StaticTable::new(['Id', 'Title'], [['1', 'alpha']])
            ->withWidthsFrom([['1', 'alpha'], ['200', 'a considerably longer title']]);

        $expected = <<<'TABLE'
            ┌─────┬─────────────────────────────┐
            │ Id  │ Title                       │
            ├─────┼─────────────────────────────┤
            │ 1   │ alpha                       │
            └─────┴─────────────────────────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($sizedForAll, 44, 8));
    }

    #[Test]
    public function headersAreWidenedToColumnsGrownByAMinimumWidth(): void
    {
        $widened = StaticTable::new(['Id', 'Title'], [['1', 'alpha']])->withMinWidth(40);

        $expected = <<<'TABLE'
            ┌────┬─────────────────────────────────┐
            │ Id │ Title                           │
            ├────┼─────────────────────────────────┤
            │ 1  │ alpha                           │
            └────┴─────────────────────────────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($widened, 44, 8));
    }

    #[Test]
    public function drawsABorderedTable(): void
    {
        $screen = $this->render($this->table(), 40, 8);

        self::assertStringContainsString('┌', $screen);
        self::assertStringContainsString('│', $screen);
        self::assertStringContainsString('├', $screen);
        self::assertStringContainsString('└', $screen);
        self::assertStringContainsString('Identifier', $screen);
    }

    #[Test]
    public function sizesAColumnByWhatItsWidestCellOccupiesOnScreen(): void
    {
        $screen = $this->render(StaticTable::new(['name', 'note'], [['ab', 'x'], ['日本語', 'y']]), 30, 8);

        self::assertStringContainsString('│ 日本語 │', $screen, 'A cell of double-width characters must sit on one row, not spill onto the next');
    }

    #[Test]
    public function fitsWhateverWidthItIsGiven(): void
    {
        foreach ([30, 44, 80] as $width) {
            foreach (explode("\n", $this->render($this->table(), $width, 12)) as $line) {
                self::assertLessThanOrEqual($width, mb_strlen(rtrim($line)), "Nothing spills at width $width");
            }
        }
    }

    #[Test]
    public function wrapsCellsThatDoNotFit(): void
    {
        $screen = $this->render($this->table(), 34, 12);

        self::assertStringContainsString('migrate', $screen);
        self::assertStringContainsString('settings', $screen, 'The end of the cell survives');
    }

    #[Test]
    public function aCellWithALineBreakIsMeasuredByItsLines(): void
    {
        $table = StaticTable::new(['Name', 'Note'], [['one', "line one\nline two"], ['two', 'short']]);
        $drawn = array_values(array_filter(
            explode("\n", $this->render($table, 40, 12)),
            static fn (string $line): bool => trim($line) !== '',
        ));

        self::assertSame(count($drawn), $table->measureHeight(40));
        self::assertSame(19, $table->drawnWidth(40), 'the note column is as wide as its widest line');
    }

    #[Test]
    public function measuresTheHeightItWouldDraw(): void
    {
        $table = $this->table();

        self::assertSame(count(explode("\n", $this->render($table, 40, 12))) - 12 + $table->measureHeight(40), $table->measureHeight(40));
        self::assertGreaterThan($table->measureHeight(80), $table->measureHeight(30), 'A narrower table is taller');
    }

    #[Test]
    public function sharesTheWidthWhenEvenOneColumnIsTooWideForThePane(): void
    {
        $table = StaticTable::new(['A', 'B'], [['a fairly long identifier value', 'and a long title as well here']]);

        foreach (explode("\n", $this->render($table, 20, 12)) as $line) {
            self::assertLessThanOrEqual(20, mb_strlen(rtrim($line)), 'Nothing spills out of the pane');
        }
    }

    #[Test]
    public function aLoneColumnTakesTheWholeWidthAndWrapsItsContent(): void
    {
        $table = StaticTable::new(['Method'], [['Acme\\NumberedPages\\Hooks\\PageRenderer']]);

        $rows = explode("\n", $this->render($table, 12, 10));

        self::assertSame('┌──────────┐', rtrim($rows[0]), 'The lone column takes all eight columns the border and padding leave it');
        self::assertStringContainsString('Acme\\Nu', $rows[3], 'The content wraps into that width');
    }

    #[Test]
    public function drawsNothingBeyondTheAreaEvenWhenOneColumnWillNotFit(): void
    {
        $table = StaticTable::new(['Class'], [['Acme\\NumberedPages\\Hooks\\PageRenderer']]);
        $frame = new Frame(40, 8);

        $table->render($frame, new Rect(0, 0, 8, 8), new RenderContext(Theme::dark()));

        for ($row = 0; $row < 8; ++$row) {
            for ($column = 8; $column < 40; ++$column) {
                self::assertSame(' ', $frame->characters[$row * 40 + $column], sprintf('Cell %d,%d lies outside the eight columns it was given', $column, $row));
            }
        }
    }

    #[Test]
    public function reservesNoRowsForAGridItCannotDraw(): void
    {
        $table = StaticTable::new(['Name', 'Size'], [['alpha', '10']]);

        self::assertSame(0, $table->measureHeight(0), 'Nothing is drawn at no width, so nothing is reserved for it');
    }

    #[Test]
    public function keepsEveryHeaderOnOneLine(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Path'],
            [['ajax_bookmark_group_delete', 'POST', '/ajax/bookmark/group/delete']],
        );

        $screen = $this->render($table, 46, 10);

        self::assertStringContainsString('Method', $screen, 'A header narrow enough to fit is never wrapped');
    }

    #[Test]
    public function breaksATwoWordHeaderBetweenItsWordsRatherThanInside(): void
    {
        $table = StaticTable::new(
            ['Pkg', 'Main level', 'Path'],
            [['acme/core', 'content', '/module/content']],
        );

        $screen = $this->render($table, 40, 10);

        self::assertStringContainsString('Main', $screen);
        self::assertStringContainsString('level', $screen, 'The second word stays whole on its own line');
    }

    #[Test]
    public function neverBreaksAHeaderWordInHalf(): void
    {
        $table = StaticTable::new(['Configuration', 'Value'], [['siteName', 'demo']]);

        $screen = $this->render($table, 27, 10);

        self::assertStringContainsString('Configuration', $screen);
    }

    #[Test]
    public function keepsEveryColumnAtItsNaturalWidthWhenTheyAllFit(): void
    {
        $screen = $this->render($this->table(), 60, 8);

        self::assertStringContainsString('setup_userSettings', $screen);
        self::assertStringContainsString('migrate the user settings', $screen, 'Nothing wraps while there is room');
    }

    /**
     * Keep wrapped tables within the available width so the closing border remains visible.
     */
    #[Test]
    public function aWrappingTableStillEndsInsideTheWidthItWasGiven(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Target'],
            [['about', 'ANY', 'Acme\Controller\AboutController::handleRequest']],
        )->withOverflow(Overflow::Wrap);

        foreach ([20, 24, 30] as $width) {
            $rows = explode("\n", $this->drawnRows($table, $width, 30));

            self::assertLessThanOrEqual($width, mb_strlen($rows[0]), sprintf('The head rule fits %d columns', $width));
            self::assertStringEndsWith('┐', $rows[0], 'so it keeps its right-hand corner');
            self::assertStringEndsWith('┘', $rows[array_key_last($rows)], 'and closes at the bottom');
        }
    }

    #[Test]
    public function stacksTheColumnsThatDoNotFitIntoTheLeadingOne(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Target', 'Type'],
            [['about', 'ANY', 'Acme\Controller\AboutController::handleRequest', 'Module']],
        );

        $rows = explode("\n", $this->render($table, 24, 10));

        $screen = implode("\n", $rows);

        self::assertStringContainsString('Name', $rows[1], 'The columns that fit stay tabular');
        self::assertStringContainsString('Target', $screen, 'A stacked column keeps its label in the header');
        self::assertStringContainsString('Type', $screen);
        self::assertStringContainsString('Acme', $screen, 'Its value sits under the value above');
    }

    #[Test]
    public function aStackedColumnKeepsItsLabelAboveItsValueAndTheRestTabular(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Target', 'Type'],
            [['about', 'ANY', 'Acme\Controller\AboutController::handleRequest', 'Module']],
        );

        $expected = <<<'TABLE'
            ┌─────────────┬────────┐
            │ Name        │ Method │
            │ Target      │        │
            │ Type        │        │
            ├─────────────┼────────┤
            │ about       │ ANY    │
            │ Acme\Contr… │        │
            │ Module      │        │
            └─────────────┴────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 24, 10));
    }

    #[Test]
    public function aSecondStackedRowIsDrawnUnderARuleOfItsOwn(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Target'],
            [
                ['about', 'ANY', 'Acme\Controller\AboutController::handleRequest'],
                ['login', 'POST', 'Acme\Controller\LoginController::handleRequest'],
            ],
        );

        $screen = $this->render($table, 24, 16);

        self::assertStringContainsString('about', $screen);
        self::assertStringContainsString('login', $screen, 'A stacked table draws every row it was given, not only the first');
        self::assertLessThan(
            mb_strpos($screen, 'login'),
            mb_strpos($screen, 'about'),
            'in the order they were handed in',
        );
    }

    #[Test]
    public function aTableWithoutHeadersStacksItsColumnsWithoutAHeader(): void
    {
        $table = StaticTable::new([], [['description', 'b c d e f g']]);

        $expected = <<<'TABLE'
            ┌──────────┐
            │ descrip… │
            │ b c d e… │
            └──────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 12, 8));
        self::assertSame(4, $table->measureHeight(12));
    }

    #[Test]
    public function keepsEveryColumnWhenAskedToWrapInstead(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Target', 'Type'],
            [['about', 'ANY', 'Acme\Controller\AboutController::handleRequest', 'Module']],
        )->withOverflow(Overflow::Wrap);

        $header = explode("\n", $this->render($table, 46, 10))[1];

        self::assertStringContainsString('Type', $header, 'Every column keeps its place');
    }

    #[Test]
    public function drawsTheSameTableForAnIdenticalRebuild(): void
    {
        $rebuilt = $this->render($this->table(), 40, 12);

        self::assertSame($this->render($this->table(), 40, 12), $rebuilt);
    }

    #[Test]
    public function drawsTheBorderStyleItWasGiven(): void
    {
        $screen = $this->render($this->table()->withBorder(BorderStyle::Double), 40, 8);

        self::assertStringContainsString('╔', $screen);
        self::assertStringContainsString('╬', $screen, 'The header rule crosses every column divider');
        self::assertStringContainsString('║', $screen);
        self::assertStringContainsString('╝', $screen);
    }

    #[Test]
    public function roundsItsCornersWhenAskedTo(): void
    {
        $screen = $this->render($this->table()->withBorder(BorderStyle::Rounded), 40, 8);

        self::assertStringContainsString('╭', $screen);
        self::assertStringContainsString('╯', $screen);
    }

    /**
     * Measure retained bytes to verify that rendering a short viewport does not build every off-screen row.
     */
    #[Test]
    public function aSmallViewportOntoAHugeTableKeepsOnlyTheLinesItShows(): void
    {
        $rows = [];
        for ($index = 0; $index < 20_000; ++$index) {
            $rows[] = ['row-' . $index, 'title ' . $index];
        }

        $table = StaticTable::new(['Identifier', 'Title'], $rows);

        $before = memory_get_usage();
        $this->render($table, 60, 10);
        $held = memory_get_usage() - $before;

        self::assertLessThan(
            200_000,
            $held,
            sprintf('A ten-row window kept %d bytes of drawn lines', $held),
        );
    }

    /**
     * When nothing wraps, compute height from row count without building rendered lines.
     */
    #[Test]
    public function sayingHowTallItIsDoesNotDrawIt(): void
    {
        $rows = [];
        for ($index = 0; $index < 20_000; ++$index) {
            $rows[] = ['row-' . $index, 'title ' . $index];
        }

        $table = StaticTable::new(['Identifier', 'Title'], $rows);

        $before = memory_get_usage();
        $height = $table->measureHeight(60);
        $held = memory_get_usage() - $before;

        self::assertSame(20_004, $height, 'a top rule, the headers and their rule, every row, and a closing rule');
        self::assertLessThan(200_000, $held, sprintf('Answering held %d bytes of drawn lines', $held));
    }

    #[Test]
    public function aTableWithoutHeadersDrawsItsCells(): void
    {
        $table = StaticTable::new([], [['alpha', '10'], ['beta', '200']]);

        $expected = <<<'TABLE'
            ┌───────┬─────┐
            │ alpha │ 10  │
            │ beta  │ 200 │
            └───────┴─────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 30, 8));
        self::assertSame(4, $table->measureHeight(30));
    }

    #[Test]
    public function aSmallWindowDrawnFirstLeavesTheWholeTableItsFullHeight(): void
    {
        $table = $this->table();
        $this->render($table, 40, 3);

        self::assertSame($this->table()->measureHeight(40), $table->measureHeight(40));
        self::assertSame($this->table()->measureHeight(36), $table->measureHeight(36), 'nor a table of another width');
    }

    #[Test]
    public function tablesThatDifferOnlyInTheirHeadersDrawTheirOwnHeaders(): void
    {
        $this->render(StaticTable::new(['Name'], [['alpha']]), 20, 6);

        self::assertStringContainsString('Title', $this->render(StaticTable::new(['Title'], [['alpha']]), 20, 6));
    }

    #[Test]
    public function columnsNarrowerThanTheMinimumStayTabularWhereTheyFitExactly(): void
    {
        $expected = <<<'TABLE'
            ┌───┬───┐
            │ A │ B │
            ├───┼───┤
            │ 1 │ 2 │
            └───┴───┘
            TABLE;

        self::assertSame($expected, $this->drawnRows(StaticTable::new(['A', 'B'], [['1', '2']]), 9, 6));
    }

    #[Test]
    public function aWrappingTableKeepsEveryColumnSideBySideWhereNotEvenTheWordsFit(): void
    {
        $table = StaticTable::new(
            ['Name', 'Method', 'Target', 'Type'],
            [['about', 'ANY', 'Acme\Controller\AboutController::handleRequest', 'Module']],
        )->withOverflow(Overflow::Wrap);

        $headRule = explode("\n", $this->drawnRows($table, 24, 20))[0];

        self::assertSame(3, mb_substr_count($headRule, '┬'));
    }

    #[Test]
    public function columnsWhoseLongestWordsFillTheWidthExactlyKeepThoseWidths(): void
    {
        $table = StaticTable::new(['A', 'B'], [['x', 'aaaaaaaaaa bbbbbbbbbb']]);

        $expected = <<<'TABLE'
            ┌──────┬────────────┐
            │ A    │ B          │
            ├──────┼────────────┤
            │ x    │ aaaaaaaaaa │
            │      │ bbbbbbbbbb │
            └──────┴────────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 21, 8));
    }

    #[Test]
    public function stackingKeepsTheCheapestColumnsInTheirOwnOrderAndCutsWhatMovesUnderTheRow(): void
    {
        $expected = <<<'TABLE'
            ┌──────────────┬───────┬──────┐
            │ Name         │ Group │ Id   │
            │ Description  │       │      │
            │ Note         │       │      │
            ├──────────────┼───────┼──────┤
            │ alpha        │ web   │ 7    │
            │ a long desc… │       │      │
            │ late         │       │      │
            ├──────────────┼───────┼──────┤
            │ beta         │ cli   │ 8    │
            │ short        │       │      │
            │ early        │       │      │
            └──────────────┴───────┴──────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($this->stackingTable(), 31, 14));
    }

    #[Test]
    public function stackingKeepsAColumnThatFillsTheWidthExactly(): void
    {
        $expected = <<<'TABLE'
            ┌───────┬───────┬──────┬───────┐
            │ Name  │ Group │ Id   │ Note  │
            │ Desc… │       │      │       │
            ├───────┼───────┼──────┼───────┤
            TABLE;

        self::assertStringStartsWith($expected, $this->drawnRows($this->stackingTable(), 32, 14));
    }

    #[Test]
    public function sharingTheWidthNeverDrawsPastIt(): void
    {
        $table = StaticTable::new(['Ab', 'Method'], [['aaaaaaaaaaaa', 'bbbbbbbbbbbb']])->withOverflow(Overflow::Wrap);

        $expected = <<<'TABLE'
            ┌──────┬────────┐
            │ Ab   │ Method │
            ├──────┼────────┤
            │ aaaa │ bbbbbb │
            │ aaaa │ bbbbbb │
            │ aaaa │        │
            └──────┴────────┘
            TABLE;

        self::assertSame($expected, $this->drawnRows($table, 17, 10));
    }

    #[Test]
    public function belowItsHeaderWordsEveryColumnStillGetsAnEvenShareAndTheFrameCloses(): void
    {
        $table = StaticTable::new(['Name', 'Kind'], [['alphabet', 'consonant']])->withOverflow(Overflow::Wrap);

        $narrowest = explode("\n", $this->drawnRows($table, 9, 20));
        $oneMore = explode("\n", $this->drawnRows($table, 10, 20));

        self::assertSame('┌───┬───┐', $narrowest[0], 'a column apiece');
        self::assertSame('┌────┬───┐', $oneMore[0], 'the column left over goes to the leftmost');
        self::assertSame('└────┴───┘', end($oneMore));
    }

    #[Test]
    public function aRowWithNoCellsStillTakesALine(): void
    {
        $table = StaticTable::new(['Name'], [[]]);

        self::assertSame(5, $table->measureHeight(20));
        self::assertCount(5, explode("\n", $this->drawnRows($table, 20, 8)));
    }

    #[Test]
    public function aSubsetIsSqueezedByTheWordsOfEveryRowItWasSizedFor(): void
    {
        $table = StaticTable::new(['A', 'B', 'C'], [['x', 'y', 'z']])
            ->withWidthsFrom([['x', 'aaaaaaaaaa', 'bbbbbbbbbb']]);

        $headRule = explode("\n", $this->drawnRows($table, 22, 10))[0];

        self::assertSame(1, mb_substr_count($headRule, '┬'), 'the third column is stacked, as it would be for the rows it was sized for');
    }

    #[Test]
    public function drawingLeavesTheFrameClippedAsItFoundIt(): void
    {
        $frame = new Frame(40, 10);

        $this->table()->render($frame, new Rect(2, 2, 30, 6), new RenderContext(Theme::dark()));

        self::assertEquals($frame->area(), $frame->clip());
    }

    private function stackingTable(): StaticTable
    {
        return StaticTable::new(
            ['Name', 'Description', 'Group', 'Id', 'Note'],
            [
                ['alpha', '  a long description here', 'web', '7', 'late'],
                ['beta', 'short', 'cli', '8', 'early'],
            ],
        );
    }

    private function table(): StaticTable
    {
        return StaticTable::new(
            ['Identifier', 'Title'],
            [['setup_userSettings', 'migrate the user settings']],
        );
    }

    private function drawnRows(StaticTable $table, int $width, int $height): string
    {
        $rows = array_filter(
            explode("\n", $this->render($table, $width, $height)),
            static fn (string $row): bool => trim($row) !== '',
        );

        return implode("\n", array_map(rtrim(...), $rows));
    }

    private function render(StaticTable $table, int $width, int $height): string
    {
        $frame = new Frame($width, $height);
        $table->render($frame, Rect::fromSize($width, $height), new RenderContext(Theme::dark()));

        $rows = [];
        for ($row = 0; $row < $height; ++$row) {
            $rows[] = implode('', array_slice($frame->characters, $row * $width, $width));
        }

        return implode("\n", $rows);
    }
}
