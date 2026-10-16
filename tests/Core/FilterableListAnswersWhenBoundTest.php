<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableListAnswersWhenBoundTest extends TestCase
{
    #[Test]
    public function typingIntoABoundFilterableListReachesItsField(): void
    {
        $harness = ModelHarness::for(SearchingApp::seeded(), 40, 10);

        $harness->dispatch(new KeyMsg('a'));

        $model = $harness->model();
        self::assertInstanceOf(SearchingApp::class, $model);
        self::assertSame('a', $model->list->query());
    }
}

final readonly class SearchingApp extends App
{
    public function __construct(public FilterableList $list)
    {
    }

    public static function seeded(): self
    {
        return new self(FilterableList::new([
            new SimpleListItem('alpha'),
            new SimpleListItem('beta'),
        ]));
    }

    public function view(): Widget
    {
        return Component::bind('search', $this->list, static fn (self $app, FilterableList $list): self => $app->cloneWith(list: $list));
    }
}
