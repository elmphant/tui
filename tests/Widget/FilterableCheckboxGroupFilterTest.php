<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupFilterTest extends TestCase
{
    private const LOGS = ['app.log', 'deprecations', 'sync_log', 'sync_trail'];

    #[Test]
    public function aHostSetsTheQueryAndReadsItBack(): void
    {
        $group = FilterableCheckboxGroup::new(self::LOGS)->withQuery('sync');

        self::assertSame('sync', $group->query());
        self::assertStringContainsString('sync_log', self::screenOf($group));
        self::assertStringNotContainsString('app.log', self::screenOf($group));
    }

    #[Test]
    public function theQueryTheReaderTypedIsTheQuery(): void
    {
        $typed = FilterableCheckboxGroup::new(self::LOGS)->update(new KeyMsg('d'), Rect::fromSize(30, 8))->model;
        self::assertInstanceOf(FilterableCheckboxGroup::class, $typed);

        self::assertSame('d', $typed->query());
    }

    #[Test]
    public function theSummaryCountsWhatTheQueryLeftOfEverything(): void
    {
        self::assertSame('2 of 4', FilterableCheckboxGroup::new(self::LOGS)->withQuery('sync')->summary());
    }

    #[Test]
    public function anEmptyFieldShowsThePlaceholderItIsGiven(): void
    {
        self::assertStringContainsString('Find a log', self::screenOf(FilterableCheckboxGroup::new(self::LOGS)->withPlaceholder('Find a log')));
    }

    #[Test]
    public function aQueryNothingMatchesSaysSo(): void
    {
        $group = FilterableCheckboxGroup::new(self::LOGS)->withQuery('zzz');

        self::assertStringContainsString('No matches', self::screenOf($group));
        self::assertStringContainsString('No log by that name', self::screenOf($group->withNoMatchText('No log by that name')));
    }

    #[Test]
    public function aGroupWithNoOptionsShowsTheTextForNoData(): void
    {
        self::assertStringContainsString('No logs yet', self::screenOf(FilterableCheckboxGroup::new([])->withNoDataText('No logs yet')));
    }

    #[Test]
    public function newOptionsKeepTheTicksOfTheOptionsThatStay(): void
    {
        $group = FilterableCheckboxGroup::new(self::LOGS)->withCheckedValues(['sync_log', 'app.log']);

        self::assertSame(['sync_log'], $group->withOptions(['sync_log', 'error.log'])->checkedValues());
    }

    #[Test]
    public function checkedValuesThatAreNotOptionsAreLeftOut(): void
    {
        self::assertSame(['deprecations'], FilterableCheckboxGroup::new(self::LOGS)->withCheckedValues(['deprecations', 'gone.log'])->checkedValues());
    }

    private static function screenOf(FilterableCheckboxGroup $group): string
    {
        $area = Rect::fromSize(30, 8);
        $frame = new Frame($area->width, $area->height);
        $group->render($frame, $area, new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame)->plainText();
    }
}
