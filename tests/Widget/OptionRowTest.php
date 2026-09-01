<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Choice\CheckboxRow;
use Elmphant\Tui\Widget\Choice\OptionRow;
use Elmphant\Tui\Widget\Choice\OptionRowLayout;
use Elmphant\Tui\Widget\Choice\RadioRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules every option row keeps, asked of each one of them.
 */
final class OptionRowTest extends TestCase
{
    /**
     * @return iterable<string, array{OptionRow}>
     */
    public static function rows(): iterable
    {
        yield 'checkbox' => [CheckboxRow::new(['system', 'pages', 'all'])];
        yield 'radio' => [RadioRow::new(['system', 'pages', 'all'])];
    }

    #[Test]
    #[DataProvider('rows')]
    public function readsBackTheOptionsItWasGiven(OptionRow $row): void
    {
        self::assertSame(['system', 'pages', 'all'], $row->options());
        self::assertSame(['one'], $row->withOptions(['one'])->options());
    }

    #[Test]
    #[DataProvider('rows')]
    public function restsItsCursorOnTheFirstOptionAndTakesItWhereItIsPut(OptionRow $row): void
    {
        self::assertSame(0, $row->cursor());
        self::assertSame(2, $row->withCursor(2)->cursor());
    }

    #[Test]
    #[DataProvider('rows')]
    public function walksTheCursorRoundRatherThanStoppingAtTheEnds(OptionRow $row): void
    {
        $focused = $row->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $left = $focused->update(new KeyMsg(Key::Left), $area)->model;
        self::assertInstanceOf(OptionRow::class, $left);
        self::assertSame(2, $left->cursor(), 'a row is short enough that coming round is quicker than turning back');

        $right = $left->update(new KeyMsg(Key::Right), $area)->model;
        self::assertInstanceOf(OptionRow::class, $right);
        self::assertSame(0, $right->cursor());
    }

    #[Test]
    #[DataProvider('rows')]
    public function aClickLeavesTheCursorOnWhatItPicked(OptionRow $row): void
    {
        $area = new Rect(0, 0, 40, 1);
        $secondOption = OptionRowLayout::placements(['( ) system', '( ) pages'], $area)[1][0];

        $clicked = $row->update(new MouseMsg($secondOption, 0, MouseAction::Press, MouseButton::Left), $area)->model;

        self::assertInstanceOf(OptionRow::class, $clicked);
        self::assertSame(1, $clicked->cursor(), 'the keys carry on from where the pointer left off');
    }

    #[Test]
    #[DataProvider('rows')]
    public function asksForAFurtherRowRatherThanRunningOffTheEdge(OptionRow $row): void
    {
        self::assertSame(1, $row->measureHeight(80), 'three short options sit on one row');
        self::assertGreaterThan(1, $row->measureHeight(12), 'and take more rows once the width runs out');
        self::assertSame(1, $row->sizing()->rows->min, 'one row is the floor, the measurement raises it');
    }

    #[Test]
    #[DataProvider('rows')]
    public function showsWhereItsCursorRestsOnceItHasTheFocus(OptionRow $row): void
    {
        $theme = Theme::dark();
        $frame = new Frame(40, 1);

        $row->withFocus(true)->render($frame, new Rect(0, 0, 40, 1), RenderContext::focused($theme));

        self::assertSame(
            $theme->cursorStyle->foregroundValue(),
            $frame->foregrounds[0],
            'the arrows would otherwise walk a cursor nothing draws',
        );
    }
}
