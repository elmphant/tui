<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\Actions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase
{
    #[Test]
    public function allActionConstants(): void
    {
        self::assertSame('focus_next', Actions::FOCUS_NEXT);
        self::assertSame('focus_prev', Actions::FOCUS_PREV);
        self::assertSame('select', Actions::SELECT);
        self::assertSame('toggle', Actions::TOGGLE);

        self::assertSame('page_up', Actions::PAGE_UP);
        self::assertSame('page_down', Actions::PAGE_DOWN);
        self::assertSame('home', Actions::HOME);
        self::assertSame('end', Actions::END);
        self::assertSame('scroll_up', Actions::SCROLL_UP);
        self::assertSame('scroll_down', Actions::SCROLL_DOWN);

        self::assertSame('cursor_left', Actions::CURSOR_LEFT);
        self::assertSame('cursor_right', Actions::CURSOR_RIGHT);

        self::assertSame('focus_cycle', Actions::FOCUS_CYCLE);
        self::assertSame('focus_prev_cycle', Actions::FOCUS_PREV_CYCLE);
        self::assertSame('tab_next', Actions::TAB_NEXT);
        self::assertSame('tab_prev', Actions::TAB_PREV);

        self::assertSame('line_start', Actions::LINE_START);
        self::assertSame('line_end', Actions::LINE_END);
        self::assertSame('delete_char_backward', Actions::DELETE_CHAR_BACKWARD);
        self::assertSame('delete_char_forward', Actions::DELETE_CHAR_FORWARD);
        self::assertSame('delete_word_backward', Actions::DELETE_WORD_BACKWARD);
        self::assertSame('delete_word_forward', Actions::DELETE_WORD_FORWARD);
    }
}
