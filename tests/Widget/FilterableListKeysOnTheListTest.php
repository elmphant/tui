<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableListKeysOnTheListTest extends TestCase
{
    #[Test]
    public function aFocusedListPutsTheCaretInItsField(): void
    {
        self::assertNotNull($this->drawn(self::list())->cursorPosition);
    }

    #[Test]
    public function aListHoldingTheKeysDrawsNoCaretButKeepsItsQuery(): void
    {
        $frame = $this->drawn(self::list()->withKeysOnTheList());

        self::assertNull($frame->cursorPosition);
        self::assertStringContainsString('on', ScreenBuffer::fromFrame($frame)->lineAt(0));
    }

    #[Test]
    public function aListHoldingTheKeysTakesNoText(): void
    {
        $list = self::list()->withKeysOnTheList();

        self::assertFalse($list->takesText());
        self::assertSame('on', $list->update(new KeyMsg('x'), new Rect(0, 0, 24, 5))->model->query());
    }

    #[Test]
    public function handingTheKeysBackToTheFieldTypesAgain(): void
    {
        $list = self::list()->withKeysOnTheList()->withKeysOnTheList(false);

        self::assertTrue($list->takesText());
        self::assertSame('onx', $list->update(new KeyMsg('x'), new Rect(0, 0, 24, 5))->model->query());
    }

    private static function list(): FilterableList
    {
        return FilterableList::new([new SimpleListItem('one'), new SimpleListItem('two')])->withQuery('on');
    }

    private function drawn(FilterableList $list): Frame
    {
        $frame = new Frame(24, 5);
        $list->render($frame, new Rect(0, 0, 24, 5), RenderContext::focused(Theme::dark()));

        return $frame;
    }
}
