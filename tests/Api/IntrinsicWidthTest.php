<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Checkbox;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\Separator;
use Elmphant\Tui\Widget\Spacer;
use Elmphant\Tui\Widget\Spinner;
use Elmphant\Tui\Widget\StatusBar;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A bar slot is placed at the width its content declares, so a widget that
 * paints its own ink has to name that width. Panes and fill-only widgets have
 * none to name — they paint whatever they are given — and saying so is the
 * point: the declaration is checked against what the widget actually draws.
 */
final class IntrinsicWidthTest extends TestCase
{
    private const GENEROUS_WIDTH = 60;
    private const GENEROUS_HEIGHT = 12;
    private const NARROW_WIDTH = 17;

    #[Test]
    #[DataProvider('contentSizedWidgets')]
    public function aContentSizedWidgetDeclaresTheWidthItPaints(Widget $widget): void
    {
        self::assertSame(
            self::inkWidth($widget, self::GENEROUS_WIDTH),
            self::declaredWidth($widget),
            'declared width must be the width it draws',
        );
    }

    /**
     * A widget with no width of its own paints whatever it is given, so it
     * declares nothing — and has to prove it fills, or the category becomes a
     * home for widgets nobody classified. Placed in a bar slot it is not drawn:
     * a slot is as wide as its content asked to be, and this asked for nothing.
     */
    #[Test]
    #[DataProvider('fillOnlyWidgets')]
    public function aFillOnlyWidgetDeclaresNothingAndPaintsToTheEdge(Widget $widget): void
    {
        self::assertSame(0, self::declaredWidth($widget), 'it has no width of its own');
        self::assertSame(self::GENEROUS_WIDTH, self::inkWidth($widget, self::GENEROUS_WIDTH));
        self::assertSame(self::NARROW_WIDTH, self::inkWidth($widget, self::NARROW_WIDTH));
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function fillOnlyWidgets(): iterable
    {
        yield 'Separator' => [Separator::new()];
    }

    /**
     * The third kind, and the reason the rule is not simply "ink equals width":
     * a spacer's whole job is to hold columns open without drawing in them, so
     * it declares a width it never paints. Placed in a bar slot it would reserve
     * blank room, which is what asking for one means.
     */
    #[Test]
    #[DataProvider('spaceReservers')]
    public function aSpaceReserverDeclaresRoomItNeverPaints(Widget $widget): void
    {
        self::assertGreaterThan(0, self::declaredWidth($widget), 'it reserves room');
        self::assertSame(0, self::inkWidth($widget, self::GENEROUS_WIDTH), 'and paints none of it');
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function spaceReservers(): iterable
    {
        yield 'Spacer' => [Spacer::of(4)];
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function contentSizedWidgets(): iterable
    {
        yield 'Text' => [Text::new('a label')];
        yield 'Checkbox' => [Checkbox::new('a label')];
        yield 'StatusBar' => [StatusBar::new('ready')];
        yield 'Spinner' => [Spinner::new('loading')];
        yield 'Select' => [Select::new(['short', 'a much longer option'])];
    }

    private static function declaredWidth(Widget $widget): int
    {
        $columns = $widget->sizing()->columns;

        return $columns->basis ?? $columns->min;
    }

    /**
     * The widest row it actually paints. Rows, not one row: an option list is
     * as wide as its longest entry, which is not necessarily the first.
     */
    private static function inkWidth(Widget $widget, int $width): int
    {
        $frame = new Frame($width, self::GENEROUS_HEIGHT);
        $widget->render($frame, Rect::fromSize($width, self::GENEROUS_HEIGHT), new RenderContext(Theme::dark()));

        $widest = 0;

        for ($row = 0; $row < self::GENEROUS_HEIGHT; ++$row) {
            for ($x = $width - 1; $x >= $widest; --$x) {
                $character = $frame->characters[$row * $width + $x];
                if ($character !== ' ' && $character !== '') {
                    $widest = $x + 1;
                    break;
                }
            }
        }

        return $widest;
    }
}
