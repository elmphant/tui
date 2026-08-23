<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Listing\ListView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase
{
    #[Test]
    public function allActionConstants(): void
    {
        self::assertSame('select', Actions::SELECT);
        self::assertSame('toggle', Actions::TOGGLE);

        self::assertSame('page_up', Actions::PAGE_UP);
        self::assertSame('page_down', Actions::PAGE_DOWN);
        self::assertSame('home', Actions::HOME);
        self::assertSame('end', Actions::END);
        self::assertSame('scroll_up', Actions::SCROLL_UP);
        self::assertSame('scroll_down', Actions::SCROLL_DOWN);

        self::assertSame('cursor_up', Actions::CURSOR_UP);
        self::assertSame('cursor_down', Actions::CURSOR_DOWN);
        self::assertSame('cursor_left', Actions::CURSOR_LEFT);
        self::assertSame('cursor_right', Actions::CURSOR_RIGHT);

        self::assertSame('focus_cycle', Actions::FOCUS_CYCLE);
        self::assertSame('focus_previous_cycle', Actions::FOCUS_PREVIOUS_CYCLE);
        self::assertSame('tab_next', Actions::TAB_NEXT);
        self::assertSame('tab_previous', Actions::TAB_PREVIOUS);

        self::assertSame('line_start', Actions::LINE_START);
        self::assertSame('line_end', Actions::LINE_END);
        self::assertSame('delete_char_backward', Actions::DELETE_CHAR_BACKWARD);
        self::assertSame('delete_char_forward', Actions::DELETE_CHAR_FORWARD);
        self::assertSame('delete_word_backward', Actions::DELETE_WORD_BACKWARD);
        self::assertSame('delete_word_forward', Actions::DELETE_WORD_FORWARD);
    }

    /**
     * `down` used to be bound to `FOCUS_NEXT`, so rebinding what looked like the tab
     * order rebound every list's arrows instead.
     */
    #[Test]
    public function movingACursorIsNotMovingTheKeyboard(): void
    {
        $list = ListView::defaultKeybindings();

        self::assertTrue($list->matches(new KeyMsg(Key::Down), Actions::CURSOR_DOWN), 'down walks the rows');
        self::assertFalse(
            $list->matches(new KeyMsg(Key::Down), Actions::FOCUS_CYCLE),
            'and says nothing about where the keyboard goes next',
        );
    }
}
