<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Checkbox;
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
     * @return iterable<string, array{Widget}>
     */
    public static function contentSizedWidgets(): iterable
    {
        yield 'Text' => [Text::new('a label')];
        yield 'Checkbox' => [Checkbox::new('a label')];
        yield 'StatusBar' => [StatusBar::new('ready')];
    }

    private static function declaredWidth(Widget $widget): int
    {
        $columns = $widget->sizing()->columns;

        return $columns->basis ?? $columns->min;
    }

    private static function inkWidth(Widget $widget, int $width): int
    {
        $frame = new Frame($width, 1);
        $widget->render($frame, Rect::fromSize($width, 1), new RenderContext(Theme::dark()));

        for ($x = $width - 1; $x >= 0; --$x) {
            if ($frame->characters[$x] !== ' ' && $frame->characters[$x] !== '') {
                return $x + 1;
            }
        }

        return 0;
    }
}
