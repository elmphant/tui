<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Listing\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListItemTest extends TestCase
{
    #[Test]
    public function simpleListItemTitle(): void
    {
        $item = new SimpleListItem('My Title');
        self::assertSame('My Title', $item->title());
    }

    #[Test]
    public function simpleListItemDescription(): void
    {
        $item = new SimpleListItem('Title', 'Description');
        self::assertSame('Description', $item->description());
    }

    #[Test]
    public function simpleListItemEmptyDescription(): void
    {
        $item = new SimpleListItem('Title');
        self::assertSame('', $item->description());
    }

    #[Test]
    public function simpleListItemFilterValueMatchesTitle(): void
    {
        $item = new SimpleListItem('Hello World');
        self::assertSame('Hello World', $item->filterValue());
    }

    #[Test]
    public function simpleListItemHeightDependsOnWidth(): void
    {
        // Height is width-aware because titles and descriptions wrap.
        $item = new SimpleListItem('Title');
        self::assertSame(1, $item->measureHeight(20));

        $itemWithDescription = new SimpleListItem('Title', 'Desc');
        self::assertSame(2, $itemWithDescription->measureHeight(20));

        $wrapping = new SimpleListItem('Title', 'A description that needs two rows');
        self::assertGreaterThan(2, $wrapping->measureHeight(20));
    }
}
