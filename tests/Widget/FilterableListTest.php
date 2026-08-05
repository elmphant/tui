<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\FilterableList;
use Elmphant\Tui\Widget\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableListTest extends TestCase
{
    private const WIDTH = 40;
    private const HEIGHT = 10;

    #[Test]
    public function narrowsToTheItemsMatchingTheQuery(): void
    {
        $list = $this->typed($this->commands(), 'warm');

        self::assertSame('1 of 3', $list->summary());
        self::assertStringContainsString('cache:warmup', $this->render($list));
        self::assertStringNotContainsString('user:create', $this->render($list));
    }

    #[Test]
    public function aSpaceTypesRatherThanSelects(): void
    {
        $list = $this->typed($this->commands(), 'cache warm');

        self::assertSame('cache warm', $list->query());
        self::assertSame('1 of 3', $list->summary());
    }

    #[Test]
    public function homeAndEndMoveTheCaretRatherThanTheCursor(): void
    {
        $list = $this->typed($this->commands(), 'cache');

        $atHome = $this->press($list, Key::Home);

        self::assertSame(0, $atHome->sourceIndex(), 'The cursor stayed on the first match');
        self::assertSame('cache', $atHome->query(), 'and the query is untouched');

        self::assertSame(0, $this->press($atHome, Key::End)->sourceIndex());
    }

    #[Test]
    public function theArrowsWalkTheFilteredList(): void
    {
        $list = $this->press($this->typed($this->commands(), 'cache'), Key::Down);

        self::assertSame(1, $list->sourceIndex(), 'cache:flush is the second source item');

        self::assertSame(0, $this->press($list, Key::Up)->sourceIndex());
    }

    #[Test]
    public function theCursorAddressesTheSourceListNotTheFilteredOne(): void
    {
        $list = $this->typed($this->commands(), 'create');

        self::assertSame(2, $list->sourceIndex(), 'user:create is third in the source list');
    }

    #[Test]
    public function aQueryMatchingNothingSelectsNothing(): void
    {
        $list = $this->typed($this->commands(), 'nonsense');

        self::assertNull($list->sourceIndex());
        self::assertSame('0 of 3', $list->summary());
        self::assertStringContainsString('No matches', $this->render($list));
    }

    #[Test]
    public function aNewQueryPutsTheCursorBackOnTheBestMatch(): void
    {
        $moved = $this->press($this->typed($this->commands(), 'cache'), Key::Down);
        self::assertSame(1, $moved->sourceIndex());

        $narrowed = $this->typed($moved, ' warm');

        self::assertSame(0, $narrowed->sourceIndex(), 'The cursor went back to the top of the new results');
    }

    #[Test]
    public function backspaceWidensTheResultsAgain(): void
    {
        $list = $this->press($this->typed($this->commands(), 'warm'), Key::Backspace);

        self::assertSame('war', $list->query());
        self::assertSame('1 of 3', $list->summary());
    }

    #[Test]
    public function anEmptyQueryKeepsEveryItem(): void
    {
        $list = FilterableList::new($this->commandItems());

        self::assertSame('3 of 3', $list->summary());
        self::assertSame(0, $list->sourceIndex());
    }

    #[Test]
    public function theBarSitsAboveTheListWithABlankRowBetween(): void
    {
        $screen = $this->rows(FilterableList::new($this->commandItems()));

        self::assertStringContainsString('Filter…', $screen[0]);
        self::assertSame('', trim($screen[1]), 'A blank row keeps the field off the first item');
        self::assertStringContainsString('cache:warmup', $screen[2]);
    }

    #[Test]
    public function theBarShowsTheQuery(): void
    {
        $bar = $this->rows($this->typed($this->commands(), 'cache'))[0];

        self::assertSame('cache', trim($bar));
    }

    #[Test]
    public function aQueryAndAHighlightPutBackReadOutAsTheyWentIn(): void
    {
        $narrowed = $this->press($this->typed($this->commands(), 'cache'), Key::Down);
        self::assertSame('cache', $narrowed->query());
        self::assertSame(1, $narrowed->sourceIndex());

        $restored = $this->commands()->withQuery('cache')->withSourceIndex(1);

        self::assertSame('cache', $restored->query());
        self::assertSame(1, $restored->sourceIndex());
        self::assertSame('2 of 3', $restored->summary(), 'the query narrowed the list again');
    }

    /**
     * @return list<SimpleListItem>
     */
    private function commandItems(): array
    {
        return [
            new SimpleListItem('cache:warmup', 'Warms every cache'),
            new SimpleListItem('cache:flush', 'Empties every cache'),
            new SimpleListItem('user:create', 'Adds a user'),
        ];
    }

    private function commands(): FilterableList
    {
        return FilterableList::new($this->commandItems())->withFocus(true);
    }

    private function typed(FilterableList $list, string $text): FilterableList
    {
        foreach (preg_split('//u', $text, flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $list = $this->send($list, new KeyMsg($character));
        }

        return $list;
    }

    private function press(FilterableList $list, Key $key): FilterableList
    {
        return $this->send($list, new KeyMsg($key));
    }

    private function send(FilterableList $list, KeyMsg $message): FilterableList
    {
        $updated = $list->update($message, $this->area())->model;
        self::assertInstanceOf(FilterableList::class, $updated);

        return $updated;
    }

    private function area(): Rect
    {
        return Rect::fromSize(self::WIDTH, self::HEIGHT);
    }

    private function render(FilterableList $list): string
    {
        return implode("\n", $this->rows($list));
    }

    /**
     * @return list<string>
     */
    private function rows(FilterableList $list): array
    {
        $frame = new Frame(self::WIDTH, self::HEIGHT);
        $list->render($frame, $this->area(), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        $rows = [];
        for ($row = 0; $row < self::HEIGHT; ++$row) {
            $rows[] = $screen->getLine($row);
        }

        return $rows;
    }
}
