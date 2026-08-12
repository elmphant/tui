<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Widget\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Matches only the last entry, whatever the query, so a test can tell the
 * supplied matcher apart from the built-in substring one.
 */
final class LastOptionOnlyMatcher implements Matcher
{
    /**
     * @param list<string> $haystack
     *
     * @return list<int>
     */
    public function matching(string $query, array $haystack): array
    {
        return $haystack === [] ? [] : [count($haystack) - 1];
    }
}

final class FilterableCheckboxGroupTest extends TestCase
{
    #[Test]
    public function narrowsTheBoxesWithTheMatcherItWasGiven(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma'])
            ->withMatcher(new LastOptionOnlyMatcher())
            ->withFocus(true);

        $ticked = self::pressed($group, new KeyMsg('z'), new KeyMsg(' '));

        self::assertSame(['gamma'], $ticked->checkedValues(), 'The supplied matcher decides which boxes a query leaves');
    }

    #[Test]
    public function aTickOutlivesTheQueryThatWasNarrowingTheBoxesWhenItWasMade(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail'])
            ->withFocus(true);

        $ticked = self::pressed(
            $group,
            new KeyMsg('s'),
            new KeyMsg('y'),
            new KeyMsg('n'),
            new KeyMsg(' '),
            new KeyMsg('u', ctrl: true),
        );

        self::assertSame(
            ['sync_log'],
            $ticked->checkedValues(),
            'a box is ticked by name, so clearing the query cannot hand the tick to whoever holds that row now',
        );
    }

    private static function pressed(FilterableCheckboxGroup $group, Message ...$messages): FilterableCheckboxGroup
    {
        $area = Rect::fromSize(30, 8);

        foreach ($messages as $message) {
            $updated = $group->update($message, $area)->model;
            self::assertInstanceOf(FilterableCheckboxGroup::class, $updated);
            $group = $updated;
        }

        return $group;
    }
}
