<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Closure;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentRegistry;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Layout\WidgetTree;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Container;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Paragraph;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\VerticalAlignment;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    #[Test]
    public function aChildDrawingPastItsAreaIsCroppedByTheContainerBorder(): void
    {
        $frame = new Frame(40, 10);
        $container = Container::around(self::widgetDrawingPastItsArea())
            ->withBorder(BorderStyle::Rounded);

        $container->render($frame, new Rect(0, 0, 12, 6), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertStringContainsString('X', $screen->getLine(1), 'Precondition: the child did draw inside the container');
        self::assertSame('', trim($screen->getTextAt(1, 12, 28)), 'nothing of it reached past the border');
        self::assertStringContainsString('╰', $screen->getLine(5), 'and the border ring is intact');
    }

    #[Test]
    public function itIsAnImmutableSingleChildContainer(): void
    {
        $child = new ContainerProbe();
        $container = Container::around($child);
        $padded = $container->withPadding(Spacing::all(1));

        self::assertNotSame($container, $padded);
        self::assertSame([$child], iterator_to_array($container->children()));
    }

    #[Test]
    public function decorationsAreAddedToTheChildConstraint(): void
    {
        $container = Container::around(new ContainerProbe(new Constraint(min: 2, max: 5, flex: 3)))
            ->withMargin(Spacing::only(top: 1, bottom: 2))
            ->withPadding(Spacing::vertical(1))
            ->withBorder(BorderStyle::Rounded);

        $constraint = $container->sizing()->rows;

        self::assertSame(9, $constraint->min);
        self::assertSame(12, $constraint->max);
        self::assertSame(3, $constraint->flex);
    }

    #[Test]
    public function anUnboundedChildConstraintStaysUnbounded(): void
    {
        $constraint = Container::around(new ContainerProbe(Constraint::flex()))
            ->withPadding(Spacing::vertical(2))
            ->sizing()->rows;

        self::assertSame(PHP_INT_MAX, $constraint->max);
    }

    #[Test]
    public function anExplicitOuterConstraintWins(): void
    {
        $expected = new Constraint(min: 4, max: 10, flex: 2);

        $actual = Container::around(new ContainerProbe())
            ->withPadding(Spacing::all(3))
            ->withSizing(Sizing::of(Constraint::flex(), $expected))
            ->sizing()->rows;

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function noDecorationsPreserveTheChildConstraintExactly(): void
    {
        $expected = new Constraint(min: 2, max: 8, flex: 3);

        $actual = Container::around(new ContainerProbe($expected))->sizing()->rows;

        self::assertSame($expected->min, $actual->min);
        self::assertSame($expected->max, $actual->max);
        self::assertSame($expected->flex, $actual->flex);
    }

    #[Test]
    public function horizontalLayoutsUseHorizontalDecorationOverhead(): void
    {
        $child = new ContainerProbe(Constraint::exact(2));
        $container = Container::around($child)
            ->withPadding(Spacing::only(top: 1, right: 2, left: 3));
        $layout = Layout::horizontal($container);

        [$containerArea, $childArea] = WidgetTree::findRects(
            $layout,
            Rect::fromSize(20, 5),
            [$container, $child],
        );

        self::assertNotNull($containerArea);
        self::assertNotNull($childArea);
        self::assertTrue((new Rect(0, 0, 7, 5))->equals($containerArea));
        self::assertTrue((new Rect(3, 1, 2, 4))->equals($childArea));
    }

    #[Test]
    public function directionalConstraintsUseTheMatchingContentDimension(): void
    {
        $container = Container::around(new ContainerProbe())
            ->withPadding(Spacing::only(top: 2, right: 1, bottom: 1, left: 1))
            ->withContentSize(width: 7, height: 2);

        $horizontal = $container->sizing()->columns;
        $vertical = $container->sizing()->rows;

        self::assertSame(9, $horizontal->min);
        self::assertSame(9, $horizontal->max);
        self::assertSame(5, $vertical->min);
        self::assertSame(5, $vertical->max);
    }

    #[Test]
    public function theChildsWantedSizeSurvivesTheOverheadAroundIt(): void
    {
        $container = Container::around(new ContainerProbe(Constraint::flex()->withBasis(12)))
            ->withPadding(Spacing::xy(horizontal: 2, vertical: 1));

        self::assertSame(16, $container->sizing()->columns->basis);
        self::assertSame(14, $container->sizing()->rows->basis);
    }

    #[Test]
    public function theChildsRefusalToShrinkSurvivesTheOverheadAroundIt(): void
    {
        $container = Container::around(new ContainerProbe(new Constraint(min: 4, max: 40, flex: 1, shrink: 0)));

        self::assertSame(0, $container->sizing()->rows->shrink);
    }

    #[Test]
    public function itResolvesMarginBorderPaddingAndCenteredContentOnce(): void
    {
        $child = new ContainerProbe();
        $container = Container::around($child)
            ->withMargin(Spacing::all(1))
            ->withBorder(BorderStyle::Solid)
            ->withPadding(Spacing::xy(horizontal: 2, vertical: 1))
            ->withContentSize(width: 6, height: 2)
            ->withHorizontalAlignment(Alignment::Center)
            ->withVerticalAlignment(VerticalAlignment::Center);

        $placements = iterator_to_array($container->childWidgets(new Rect(0, 0, 20, 10)));

        self::assertCount(1, $placements);
        self::assertSame($child, $placements[0][0]);
        self::assertTrue((new Rect(7, 4, 6, 2))->equals($placements[0][1]));
    }

    #[Test]
    public function rightAndBottomAlignmentPlaceContentAtTheFarEdge(): void
    {
        $container = Container::around(new ContainerProbe())
            ->withPadding(Spacing::all(1))
            ->withContentSize(4, 2)
            ->withHorizontalAlignment(Alignment::Right)
            ->withVerticalAlignment(VerticalAlignment::Bottom);

        [, $area] = iterator_to_array($container->childWidgets(Rect::fromSize(12, 8)))[0];

        self::assertTrue((new Rect(7, 5, 4, 2))->equals($area));
    }

    #[Test]
    public function requestedContentSizeIsClampedToTheAvailableArea(): void
    {
        $container = Container::around(new ContainerProbe())
            ->withPadding(Spacing::all(1))
            ->withContentSize(100, 100)
            ->withHorizontalAlignment(Alignment::Center)
            ->withVerticalAlignment(VerticalAlignment::Center);

        [, $area] = iterator_to_array($container->childWidgets(Rect::fromSize(8, 5)))[0];

        self::assertTrue((new Rect(1, 1, 6, 3))->equals($area));
    }

    #[Test]
    public function renderUsesTheResolvedChildAreaAndInheritsSurfaceStyle(): void
    {
        $child = new ContainerProbe();
        $background = Color::ansi(4);
        $container = Container::around($child)
            ->withMargin(Spacing::all(1))
            ->withBorder(BorderStyle::Solid)
            ->withPadding(Spacing::all(1))
            ->withStyle(new Style(
                foreground: Color::ansi(2),
                background: $background,
                attributes: Attr::BOLD,
            ));
        $frame = new Frame(9, 7);
        $context = new RenderContext(Theme::dark());

        $container->render($frame, Rect::fromSize(9, 7), $context);

        self::assertTrue((new Rect(3, 3, 3, 1))->equals($child->renderedArea));
        self::assertSame('x', $frame->character(3 * $frame->width + 3));
        self::assertSame($background->value, $frame->background(3 * $frame->width + 3));
        self::assertSame(Attr::BOLD, $frame->attribute(3 * $frame->width + 3));
        self::assertSame('┌', $frame->character(1 * $frame->width + 1));
        self::assertSame($background->value, $frame->background(1 * $frame->width + 1));
        self::assertSame(0, $frame->background(0));
    }

    #[Test]
    public function surfaceStyleFillsDefaultsWithoutOverwritingExplicitChildColors(): void
    {
        $foreground = Color::ansi(2);
        $background = Color::ansi(4);
        $childForeground = Color::ansi(6);
        $childBackground = Color::ansi(3);
        $container = Container::around(new StyledContainerProbe(
            new Style($childForeground, $childBackground, Attr::ITALIC),
        ))->withStyle(new Style($foreground, $background, Attr::BOLD));
        $frame = new Frame(4, 2);

        $container->render($frame, Rect::fromSize(4, 2), new RenderContext(Theme::dark()));

        self::assertSame($childForeground->value, $frame->foreground(0));
        self::assertSame($childBackground->value, $frame->background(0));
        self::assertSame(Attr::ITALIC | Attr::BOLD, $frame->attribute(0));
        self::assertSame($foreground->value, $frame->foreground(1));
        self::assertSame($background->value, $frame->background(1));
        self::assertSame(Attr::BOLD, $frame->attribute(1));
        self::assertArrayHasKey(0, $frame->dirtyRows);
        self::assertArrayHasKey(1, $frame->dirtyRows);
    }

    #[Test]
    public function anEmptySurfaceStyleDoesNotDirtyAnUntouchedFrame(): void
    {
        $frame = new Frame(4, 2);
        $container = Container::around(new EmptyContainerProbe())
            ->withStyle(new Style());

        $container->render($frame, Rect::fromSize(4, 2), new RenderContext(Theme::dark()));

        self::assertSame([], $frame->dirtyRows);
    }

    #[Test]
    public function eachSurfaceColorChannelMarksItsRowDirtyIndependently(): void
    {
        foreach ([
            new Style(foreground: Color::ansi(2)),
            new Style(background: Color::ansi(4)),
        ] as $style) {
            $frame = new Frame(1, 1);
            Container::around(new EmptyContainerProbe())
                ->withStyle($style)
                ->render($frame, Rect::fromSize(1, 1), new RenderContext(Theme::dark()));

            self::assertArrayHasKey(0, $frame->dirtyRows);
        }
    }

    #[Test]
    public function surfaceStylingIsClippedToTheFrameAndContainerArea(): void
    {
        $background = Color::ansi(4);
        $frame = new Frame(3, 3);
        $container = Container::around(new EmptyContainerProbe())
            ->withStyle(new Style(background: $background));

        $container->render($frame, new Rect(-1, -1, 3, 3), new RenderContext(Theme::dark()));

        self::assertCount(9, $frame->backgrounds);
        self::assertSame($background->value, $frame->background(0));
        self::assertSame($background->value, $frame->background(1));
        self::assertSame($background->value, $frame->background(3));
        self::assertSame($background->value, $frame->background(4));
        self::assertSame(0, $frame->background(2));
        self::assertSame(0, $frame->background(6));
    }

    #[Test]
    public function aCustomBorderStyleOverridesTheThemeBorderStyle(): void
    {
        $borderStyle = new Style(foreground: Color::ansi(5));
        $frame = new Frame(5, 3);
        $container = Container::around(new EmptyContainerProbe())
            ->withBorder(BorderStyle::Solid)
            ->withBorderStyle($borderStyle);

        $container->render($frame, Rect::fromSize(5, 3), new RenderContext(Theme::dark()));

        self::assertSame('┌', $frame->character(0));
        self::assertSame($borderStyle->foregroundValue(), $frame->foreground(0));
    }

    #[Test]
    public function aBorderRendersWithoutPaddingConfiguration(): void
    {
        $frame = new Frame(5, 3);

        Container::around(new EmptyContainerProbe())
            ->withBorder(BorderStyle::Rounded)
            ->render($frame, Rect::fromSize(5, 3), new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->character(0));
    }

    #[Test]
    public function layoutPlanningExposesTheExactDecoratedChildRect(): void
    {
        $child = new ContainerProbe();
        $container = Container::around($child)
            ->withMargin(Spacing::all(1))
            ->withPadding(Spacing::horizontal(2));

        [$resolved] = WidgetTree::findRects($container, Rect::fromSize(20, 5), [$child]);

        self::assertNotNull($resolved);
        self::assertTrue((new Rect(3, 1, 14, 3))->equals($resolved));
    }

    #[Test]
    public function wrappingAComponentPreservesComponentDiscovery(): void
    {
        $app = new ContainerHost();
        $component = Component::bind(
            'input',
            $app->input,
            static fn (ContainerHost $current, TextInput $input): ContainerHost => new ContainerHost($input),
        );

        $registry = ComponentRegistry::fromWidget(Container::around($component)->withPadding(Spacing::all(1)));

        self::assertSame([$component], $registry->all());
        self::assertSame([$component], $registry->focusables());
    }

    #[Test]
    public function measuredWidthAddsWhatItDrawsAroundTheChild(): void
    {
        $child = Paragraph::fromText('twelve chars');
        $container = Container::around($child)
            ->withMargin(Spacing::horizontal(1))
            ->withBorder(BorderStyle::Rounded)
            ->withPadding(Spacing::xy(horizontal: 2, vertical: 1));

        self::assertSame(
            $child->sizing()->columns->basis + 2 + 2 + 4,
            $container->sizing()->columns->basis,
        );
    }

    #[Test]
    public function measuredHeightDelegatesWithHorizontalDecorationsRemoved(): void
    {
        $child = new MeasuringContainerProbe();
        $container = Container::around($child)
            ->withMargin(Spacing::horizontal(1))
            ->withBorder(BorderStyle::Rounded)
            ->withPadding(Spacing::xy(horizontal: 2, vertical: 1));

        self::assertSame(7, $container->measureHeight(20));
        self::assertSame(12, $child->measuredWidth);
    }

    #[Test]
    public function measuredWidthNeverBecomesNegativeOrArtificiallyPositive(): void
    {
        $child = new MeasuringContainerProbe();
        $container = Container::around($child)
            ->withMargin(Spacing::horizontal(2));

        $container->measureHeight(2);

        self::assertSame(0, $child->measuredWidth);
    }

    #[Test]
    public function undecoratedMeasurementPassesTheFullWidthToTheChild(): void
    {
        $child = new MeasuringContainerProbe();

        Container::around($child)->measureHeight(17);

        self::assertSame(17, $child->measuredWidth);
    }

    #[Test]
    public function explicitContentHeightControlsMeasuredHeight(): void
    {
        $child = new MeasuringContainerProbe();
        $container = Container::around($child)
            ->withContentHeight(4)
            ->withPadding(Spacing::vertical(1));

        self::assertSame(6, $container->measureHeight(20));
        self::assertNull($child->measuredWidth);
    }

    #[Test]
    #[DataProvider('invalidContentDimensions')]
    public function contentDimensionsMustBeNonNegative(Closure $configure, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $configure(Container::around(new ContainerProbe()));
    }

    /**
     * @return iterable<string, array{Closure(Container): void, string}>
     */
    public static function invalidContentDimensions(): iterable
    {
        yield 'width' => [
            static function (Container $container): void { $container->withContentWidth(-1); },
            'Container content width must be non-negative, got -1',
        ];
        yield 'height' => [
            static function (Container $container): void { $container->withContentHeight(-2); },
            'Container content height must be non-negative, got -2',
        ];
        yield 'size width' => [
            static function (Container $container): void { $container->withContentSize(-3, 1); },
            'Container content width must be non-negative, got -3',
        ];
        yield 'size height' => [
            static function (Container $container): void { $container->withContentSize(1, -4); },
            'Container content height must be non-negative, got -4',
        ];
    }

    #[Test]
    public function zeroSizedContentIsValidAndReceivesAnEmptyRect(): void
    {
        $container = Container::around(new ContainerProbe())
            ->withContentSize(0, 0);

        [, $area] = iterator_to_array($container->childWidgets(Rect::fromSize(10, 5)))[0];

        self::assertTrue((new Rect(0, 0, 0, 0))->equals($area));
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
}

final class ContainerProbe implements Widget
{
    public ?Rect $renderedArea = null;

    public function __construct(private readonly Constraint $size = new Constraint(min: 1, max: 1))
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of($this->size, $this->size);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->renderedArea = $area;
        $frame->putString($area->x, $area->y, 'x');
    }
}

final class MeasuringContainerProbe implements Widget, MeasureHeight
{
    public ?int $measuredWidth = null;

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function measureHeight(int $width): int
    {
        $this->measuredWidth = $width;

        return 3;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class StyledContainerProbe implements Widget
{
    public function __construct(private Style $style)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putStyled($area->x, $area->y, 'x', $this->style);
    }
}

final readonly class EmptyContainerProbe implements Widget
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class ContainerHost extends App
{
    public function __construct(public TextInput $input = new TextInput())
    {
    }

    public function view(): Widget
    {
        return $this->input;
    }
}
