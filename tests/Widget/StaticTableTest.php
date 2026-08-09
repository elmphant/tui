<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Overflow;
use Elmphant\Tui\Widget\StaticTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaticTableTest extends TestCase
{
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

    private function table(): StaticTable
    {
        return StaticTable::new(
            ['Identifier', 'Title'],
            [['setup_userSettings', 'migrate the user settings']],
        );
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
