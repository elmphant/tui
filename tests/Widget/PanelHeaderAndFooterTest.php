<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Geometry;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelHeaderAndFooterTest extends TestCase
{
    #[Test]
    public function aFooterStaysAtTheBottomInsideTheBorderWhileTheRowsScrollAboveIt(): void
    {
        $panel = self::tenRows()->withFooter(Text::new('RUN'));

        $screen = self::drawn($panel->withScrollOffset(3), 20, 8);

        self::assertStringContainsString('RUN', $screen->lineAt(6));
        self::assertStringContainsString('row 3', $screen->lineAt(1));
        self::assertStringContainsString('row 7', $screen->lineAt(5));
        self::assertStringNotContainsString('row 8', $screen->plainText());
    }

    #[Test]
    public function aHeaderStaysAtTheTopAndTheRowsBeginBelowIt(): void
    {
        $screen = self::drawn(self::tenRows()->withHeader(Text::new('FILTER')), 20, 8);

        self::assertStringContainsString('FILTER', $screen->lineAt(1));
        self::assertStringContainsString('row 0', $screen->lineAt(2));
    }

    #[Test]
    public function theRowsScrollInWhatTheHeaderAndFooterLeave(): void
    {
        $panel = self::tenRows()
            ->withHeader(Text::new('FILTER'))
            ->withFooter(Paragraph::fromText("one\ntwo"));

        self::assertEquals(new Rect(1, 2, 18, 3), $panel->contentArea(Rect::fromSize(20, 8)));
    }

    #[Test]
    public function aPanelMeasuresItsHeaderAndFooterWithItsRows(): void
    {
        $plain = self::tenRows();

        self::assertSame(
            $plain->measureHeight(20) + 3,
            $plain->withHeader(Text::new('FILTER'))->withFooter(Paragraph::fromText("one\ntwo"))->measureHeight(20),
        );
    }

    #[Test]
    public function aRowScrolledUnderTheFooterIsOutOfThePointersReach(): void
    {
        $fields = array_map(static fn (int $row): TextInput => TextInput::new()->withPlaceholder('field ' . $row), range(0, 9));
        $pane = Component::bind(
            'pane',
            Panel::new('Form')->withChildren($fields)->withFooter(Text::new('RUN')),
            static fn (object $host, Panel $panel): object => $host,
        );

        $geometry = Geometry::of($pane, Rect::fromSize(20, 8));

        self::assertSame('pane/4', $geometry->componentAt(2, 5)?->value, 'precondition: the last row above the footer is a field');
        self::assertSame('pane', $geometry->componentAt(2, 6)?->value);
    }

    #[Test]
    public function aRuledFooterIsSeparatedByALineJoinedIntoTheBorder(): void
    {
        $screen = self::drawn(self::tenRows()->withFooter(Text::new('RUN'), ruled: true), 20, 8);

        self::assertSame('├' . str_repeat('─', 18) . '┤', $screen->lineAt(5));
        self::assertStringContainsString('RUN', $screen->lineAt(6));
    }

    #[Test]
    public function aCaptionRidesOnTheRuleAtItsRightEnd(): void
    {
        $screen = self::drawn(self::tenRows()->withFooter(Text::new('RUN'), ruled: true, caption: 'why'), 20, 8);

        self::assertSame('├' . str_repeat('─', 13) . ' why ┤', $screen->lineAt(5));
    }

    #[Test]
    public function aGapAboveTheRuleIsKeptWhileTheRowsStillFit(): void
    {
        $panel = self::rows(2)->withFooter(Text::new('RUN'), ruled: true, gap: 1);

        self::assertEquals(new Rect(1, 1, 18, 3), $panel->contentArea(Rect::fromSize(20, 8)));
        self::assertSame(str_repeat(' ', 18), mb_substr(self::drawn($panel, 20, 8)->lineAt(4), 1, 18));
    }

    #[Test]
    public function theGapIsGivenUpBeforeARowOfContentWouldBe(): void
    {
        $panel = self::tenRows()->withFooter(Text::new('RUN'), ruled: true, gap: 1);

        self::assertEquals(new Rect(1, 1, 18, 4), $panel->contentArea(Rect::fromSize(20, 8)));
    }

    #[Test]
    public function theFooterIsWhereAHostAimsAPressAtWhatItDraws(): void
    {
        $panel = self::tenRows()
            ->withPadding(Spacing::only(top: 1, right: 1, bottom: 0, left: 1))
            ->withFooter(Paragraph::fromText("one\ntwo"), ruled: true);

        self::assertEquals(new Rect(2, 5, 16, 2), $panel->footerArea(Rect::fromSize(20, 8)));
    }

    #[Test]
    public function aPanelMeasuresItsRuleAndGapWithItsRows(): void
    {
        $plain = self::rows(2);

        self::assertSame(
            $plain->measureHeight(20) + 3,
            $plain->withFooter(Text::new('RUN'), ruled: true, gap: 1)->measureHeight(20),
        );
    }

    #[Test]
    public function aRuledHeaderIsSeparatedByALineJoinedIntoTheBorderBelowIt(): void
    {
        $screen = self::drawn(self::tenRows()->withHeader(Text::new('HEAD'), ruled: true), 20, 8);

        self::assertStringContainsString('HEAD', $screen->lineAt(1));
        self::assertSame('├' . str_repeat('─', 18) . '┤', $screen->lineAt(2));
        self::assertStringContainsString('row 0', $screen->lineAt(3));
    }

    #[Test]
    public function aGapBelowTheHeadersRuleIsKeptWhileTheRowsStillFit(): void
    {
        $fits = self::rows(2)->withHeader(Text::new('HEAD'), ruled: true, gap: 1);
        $overflows = self::tenRows()->withHeader(Text::new('HEAD'), ruled: true, gap: 1);

        self::assertEquals(new Rect(1, 4, 18, 3), $fits->contentArea(Rect::fromSize(20, 8)));
        self::assertEquals(new Rect(1, 3, 18, 4), $overflows->contentArea(Rect::fromSize(20, 8)));
    }

    private static function tenRows(): Panel
    {
        return self::rows(10);
    }

    private static function rows(int $count): Panel
    {
        return Panel::new('Rows')->withChildren(array_map(
            static fn (int $row): Text => Text::new('row ' . $row),
            range(0, $count - 1),
        ));
    }

    private static function drawn(Panel $panel, int $width, int $height): ScreenBuffer
    {
        $frame = new Frame($width, $height);
        $panel->render($frame, Rect::fromSize($width, $height), RenderContext::unfocused(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }
}
