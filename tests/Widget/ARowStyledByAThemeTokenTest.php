<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ARowStyledByAThemeTokenTest extends TestCase
{
    #[Test]
    #[DataProvider('themes')]
    public function itDrawsInTheColourTheThemeGivesThatToken(Theme $theme): void
    {
        $frame = new Frame(30, 4);
        Table::new([Column::new('Task', Constraint::flex())], [Row::new('first'), Row::new('broken')->withStyle(StyleToken::Error)])
            ->render($frame, Rect::fromSize(30, 4), new RenderContext($theme));

        $screen = ScreenBuffer::fromFrame($frame);
        $broken = $screen->findOrFail('broken');

        self::assertEquals($theme->error->foreground, $screen->styleAt($broken['column'], $broken['row'])->foreground);
    }

    /**
     * @return iterable<string, array{Theme}>
     */
    public static function themes(): iterable
    {
        yield 'dark' => [Theme::dark()];
        yield 'light' => [Theme::light()];
    }
}
