<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase
{
    #[Test]
    public function aBoxTooSmallForItsBorderShowsItsContentWithoutOne(): void
    {
        $frame = new Frame(10, 2);

        Box::new('Held')->add(Text::new('hi'))->render($frame, new Rect(0, 0, 2, 2), RenderContext::unfocused(Theme::dark()));

        self::assertSame('hi', ScreenBuffer::fromFrame($frame)->textAt(0, 0, 2));
    }

    #[Test]
    public function itExposesEachChildStackedInsideTheBorder(): void
    {
        $first = Text::new('one');
        $second = Text::new('two');

        $placements = iterator_to_array(Box::new('Held')->add($first)->add($second)->placedChildren(new Rect(0, 0, 12, 6)));

        self::assertSame([$first, $second], [$placements[0][0], $placements[1][0]]);
        self::assertTrue((new Rect(1, 1, 10, 1))->equals($placements[0][1]));
        self::assertTrue((new Rect(1, 2, 10, 1))->equals($placements[1][1]));
    }

    #[Test]
    public function aChildThatWrapsIsGivenEveryLineItNeeds(): void
    {
        $wrapping = Paragraph::fromText('one two three four five six seven eight nine ten');

        $placements = iterator_to_array(Box::new('Held')->add($wrapping)->placedChildren(new Rect(0, 0, 14, 12)));

        self::assertSame(
            5,
            $placements[0][1]->height,
            'the text wraps to five lines at this width, and a box that hands it one drops four',
        );
    }

    #[Test]
    public function itMeasuresTheRowsItsChildrenWrapTo(): void
    {
        $box = Box::new('Held')->add(Paragraph::fromText('one two three four five six seven eight nine ten'));

        self::assertSame(7, $box->measureHeight(14), 'five wrapped lines, and the border above and below them');
    }

    #[Test]
    public function aChildDrawingPastItsAreaIsCroppedByTheBoxBorder(): void
    {
        $frame = new Frame(40, 10);

        Box::new('Held')->add(self::widgetDrawingPastItsArea())
            ->render($frame, new Rect(0, 0, 12, 6), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertStringContainsString('X', $screen->lineAt(1), 'Precondition: the child did draw inside the box');
        self::assertSame('', trim($screen->textAt(12, 1, 28)), 'nothing of it reached past the border');
        self::assertStringContainsString('╰', $screen->lineAt(5), 'and the border ring is intact');
    }

    #[Test]
    public function implementsWidget(): void
    {
        self::assertInstanceOf(Widget::class, Box::new());
    }

    #[Test]
    public function itsHeightCountsItsBorderAndItsChildren(): void
    {
        $box = Box::new()
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'));

        self::assertSame(4, $box->measureHeight(20));
    }

    #[Test]
    public function itsHeightCountsThePaddingToo(): void
    {
        $box = Box::new()
            ->add(Text::new('Hello'))
            ->withPadding(Spacing::vertical(1));

        self::assertSame(5, $box->measureHeight(20));
    }

    #[Test]
    public function itAsksToBeMeasuredRatherThanGrowingToFill(): void
    {
        $rows = Box::new()->add(Text::new('Hello'))->sizing()->rows;

        self::assertSame(0, $rows->flex, 'a box is as tall as what it holds and no taller');
        self::assertTrue($rows->measuresContent, 'and the height it holds is answered by measuring');
    }

    #[Test]
    public function anEmptyBoxIsJustItsBorder(): void
    {
        self::assertSame(2, Box::new()->measureHeight(20));
    }

    #[Test]
    public function rendersBorder(): void
    {
        $box = Box::new();
        $frame = new Frame(10, 3);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 10, 3), $context);

        self::assertSame('─', $frame->characters[1]);
        self::assertSame('─', $frame->characters[8]);
    }

    #[Test]
    public function rendersTitle(): void
    {
        $box = Box::new('Info');
        $frame = new Frame(20, 3);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 3), $context);

        self::assertSame('I', $frame->characters[2]);
        self::assertSame('n', $frame->characters[3]);
        self::assertSame('f', $frame->characters[4]);
        self::assertSame('o', $frame->characters[5]);
    }

    #[Test]
    public function rendersChildContent(): void
    {
        $box = Box::new()
            ->add(Text::new('Hello'));
        $frame = new Frame(20, 3);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 3), $context);

        self::assertSame('H', $frame->characters[20 + 1]);
        self::assertSame('e', $frame->characters[20 + 2]);
    }

    #[Test]
    public function paddingShiftsContent(): void
    {
        $box = Box::new()
            ->add(Text::new('Hi'))
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 4), $context);

        self::assertSame('H', $frame->characters[20 + 2]);
        self::assertSame('i', $frame->characters[20 + 3]);
    }

    #[Test]
    public function skipsRenderWhenTooSmall(): void
    {
        $box = Box::new('Title');
        $frame = new Frame(10, 2);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 2, 2), $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function multipleChildren(): void
    {
        $box = Box::new()
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'));
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 4), $context);

        self::assertSame('L', $frame->characters[20 + 1]);
        self::assertSame('L', $frame->characters[40 + 1]);
    }

    #[Test]
    public function childrenClippedToContentArea(): void
    {
        $box = Box::new()
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'))
            ->add(Text::new('Line 3'));
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 4), $context);

        self::assertSame('L', $frame->characters[20 + 1]);
        self::assertSame('L', $frame->characters[40 + 1]);
        $bottomBorder = $frame->characters[60];
        self::assertNotSame('L', $bottomBorder);
    }

    #[Test]
    public function customBorderStyle(): void
    {
        $style = new Style(foreground: Color::hex('FF0000'));
        $box = Box::new()->withBorderStyle($style);
        $frame = new Frame(10, 3);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 10, 3), $context);

        $foregroundValue = Color::hex('FF0000')->value;
        self::assertSame($foregroundValue, $frame->foregrounds[0]);
    }

    #[Test]
    public function addReturnsSelf(): void
    {
        $box = Box::new();
        $updated = $box->add(Text::new('x'));

        self::assertNotSame($box, $updated);
    }

    #[Test]
    public function isNotInteractive(): void
    {
        self::assertNotInstanceOf(Interactive::class, Box::new());
    }

    #[Test]
    public function aChildThatAsksForNoRowsStillGetsOne(): void
    {
        $box = Box::new()->add($this->createWidgetWithConstraint(Constraint::exact(0)));

        self::assertSame(3, $box->measureHeight(20), 'a child drawing nothing would otherwise vanish');
    }

    #[Test]
    public function rendersWithExactMinimumWidth(): void
    {
        $box = Box::new();
        $frame = new Frame(3, 3);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 3, 3), $context);

        self::assertNotSame(' ', $frame->characters[0], 'Width=3 must render border');
    }

    #[Test]
    public function skipsRenderWhenOnlyWidthTooNarrow(): void
    {
        $box = Box::new();
        $frame = new Frame(10, 5);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 2, 5), $context);

        self::assertSame(' ', $frame->characters[0], 'Width=2 must skip even when height is sufficient');
    }

    #[Test]
    public function customTitleStyleOverridesTheme(): void
    {
        $customTitleStyle = new Style(foreground: Color::hex('00FF00'));
        $box = Box::new('Test')->withTitleStyle($customTitleStyle);
        $frame = new Frame(20, 3);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 3), $context);

        $expectedForeground = Color::hex('00FF00')->value;
        $themeTitleForeground = Theme::dark()->title->foregroundValue();
        self::assertNotSame($expectedForeground, $themeTitleForeground, 'Precondition: custom differs from theme');
        self::assertSame($expectedForeground, $frame->foregrounds[2], 'Title must use custom style, not theme');
    }

    /**
     * Box height=4, border takes top+bottom rows, leaving 2 content rows.
     * Two children each want 1 row — they exactly fill the content area.
     * A third child should NOT render because childY == bottom().
     */
    #[Test]
    public function childAtExactBoundaryIsNotRendered(): void
    {
        $renderCount = 0;
        $spyWidget = $this->createSpyWidget(Constraint::exact(1), $renderCount);

        $box = Box::new()
            ->add(Text::new('A'))
            ->add(Text::new('B'))
            ->add($spyWidget);

        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 20, 4), $context);

        self::assertSame(0, $renderCount, 'Third child must not be rendered when content area is full');
    }

    #[Test]
    public function zeroMinChildRendersWithAtLeastOneRowHeight(): void
    {
        $zeroMinWidget = $this->createWidgetWithConstraint(Constraint::exact(0), 'X');

        $box = Box::new()->add($zeroMinWidget);
        $frame = new Frame(10, 4);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 10, 4), $context);

        self::assertSame('X', $frame->characters[10 + 1], 'Zero-min child must still get 1 row for rendering');
    }

    #[Test]
    public function childReceivesClippedHeightNotInflatedHeight(): void
    {
        $receivedHeight = null;
        $spyWidget = new class(Constraint::exact(3), $receivedHeight) implements Widget {
            public function __construct(
                private readonly Constraint $widgetConstraint,
                private ?int &$receivedHeight,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of($this->widgetConstraint, $this->widgetConstraint);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $this->receivedHeight = $area->height;
            }
        };

        $box = Box::new()->add($spyWidget);
        $frame = new Frame(10, 4);
        $context = new RenderContext(Theme::dark());

        $box->render($frame, new Rect(0, 0, 10, 4), $context);

        self::assertSame(2, $receivedHeight, 'Child height must be clipped to available space (bottom - childY), not inflated');
    }

    private static function widgetDrawingPastItsArea(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, str_repeat('X', 36));
                $frame->putString($area->x, $area->bottom(), str_repeat('X', 6));
            }
        };
    }

    private function createSpyWidget(Constraint $constraint, int &$renderCount): Widget
    {
        return new class($constraint, $renderCount) implements Widget {
            public function __construct(
                private readonly Constraint $widgetConstraint,
                private int &$renderCount,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of($this->widgetConstraint, $this->widgetConstraint);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                ++$this->renderCount;
            }
        };
    }

    private function createWidgetWithConstraint(Constraint $constraint, string $fillChar = ' '): Widget
    {
        return new class($constraint, $fillChar) implements Widget {
            public function __construct(
                private readonly Constraint $widgetConstraint,
                private readonly string $fillChar,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of($this->widgetConstraint, $this->widgetConstraint);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($row = $area->y; $row < $area->y + $area->height; ++$row) {
                    $frame->putString($area->x, $row, $this->fillChar);
                }
            }
        };
    }
}
