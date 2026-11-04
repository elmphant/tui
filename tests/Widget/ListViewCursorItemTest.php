<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Listing\ListItem;
use Elmphant\Tui\Widget\Listing\ListItemBehavior;
use Elmphant\Tui\Widget\Listing\ListView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListViewCursorItemTest extends TestCase
{
    #[Test]
    public function aRecordCanBeTheItemTheListShows(): void
    {
        $list = ListView::new([new Chore(1, 'Buy milk'), new Chore(2, 'Call Ada')])->withCursor(1);

        self::assertEquals(new Chore(2, 'Call Ada'), $list->cursorItem());
        self::assertSame('[ ] Call Ada', $list->cursorItem()?->title());
    }

    #[Test]
    public function anEmptyListHasNoItemUnderTheCursor(): void
    {
        self::assertNull(ListView::new([])->cursorItem());
    }

    #[Test]
    public function theItemUnderTheCursorIsReplacedInPlace(): void
    {
        $list = ListView::new([new Chore(1, 'Buy milk'), new Chore(2, 'Call Ada')])->withCursor(1);
        $chore = $list->cursorItem();
        self::assertInstanceOf(Chore::class, $chore);

        $toggled = $list->withCursorItem($chore->toggled());

        self::assertEquals([new Chore(1, 'Buy milk'), new Chore(2, 'Call Ada', done: true)], $toggled->items());
        self::assertSame(1, $toggled->cursor(), 'the cursor stays on the row it changed');
    }

    #[Test]
    public function theItemUnderTheCursorIsRemovedAndTheCursorStaysInTheList(): void
    {
        $list = ListView::new([new Chore(1, 'Buy milk'), new Chore(2, 'Call Ada'), new Chore(3, 'Pay rent')])->withCursor(2);

        $removed = $list->withoutCursorItem();

        self::assertEquals([new Chore(1, 'Buy milk'), new Chore(2, 'Call Ada')], $removed->items());
        self::assertSame(1, $removed->cursor(), 'the last row went, so the cursor moves up to the new last one');
    }

    #[Test]
    public function anEmptyListIsLeftAsItIs(): void
    {
        $list = ListView::new([]);

        self::assertSame($list, $list->withoutCursorItem());
        self::assertSame($list, $list->withCursorItem(new Chore(1, 'Buy milk')));
    }

    #[Test]
    public function aRecordNeedsOnlyATitleAndAKey(): void
    {
        $chore = new Chore(7, 'Buy milk');

        self::assertSame('', $chore->description());
        self::assertSame('[ ] Buy milk', $chore->filterValue());
        self::assertSame(1, $chore->measureHeight(40));
    }
}

final readonly class Chore implements ListItem
{
    use ListItemBehavior;

    public function __construct(public int $id, public string $name, public bool $done = false)
    {
    }

    public function title(): string
    {
        return ($this->done ? '[x] ' : '[ ] ') . $this->name;
    }

    public function key(): string
    {
        return (string)$this->id;
    }

    public function toggled(): self
    {
        return new self($this->id, $this->name, !$this->done);
    }
}
