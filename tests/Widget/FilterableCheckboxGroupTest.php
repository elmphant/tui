<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use InvalidArgumentException;
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
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'toggle'],
            FilterableCheckboxGroup::new(['alpha'])->hints(),
        );
    }

    #[Test]
    public function aGroupWithSomethingTypedInItNamesTheKeyThatClearsIt(): void
    {
        $typed = self::pressed(
            FilterableCheckboxGroup::new(['alpha', 'beta'])->withFocus(true),
            new KeyMsg('a'),
        );

        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'toggle', 'Esc' => 'clear filter'],
            $typed->hints(),
        );
    }

    #[Test]
    public function aGlobalRebindingWalksTheBoxesInsteadOfTypingIntoTheField(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta'])
            ->withFocus(true)
            ->withGlobalKeybindings(VimMode::keybindings());

        $ticked = self::pressed($group, new KeyMsg('j'), new KeyMsg(' '));

        self::assertSame(
            ['beta'],
            $ticked->checkedValues(),
            'vim mode bound j to the cursor, so it walks the boxes rather than landing in the filter',
        );
    }

    #[Test]
    public function aMatcherNamingTheSameOptionTwiceStillDrawsOneBoxForIt(): void
    {
        $repeating = new class implements Matcher {
            /**
             * @param list<string> $haystack
             *
             * @return list<int>
             */
            public function matching(string $query, array $haystack): array
            {
                return $haystack === [] ? [] : [0, 0];
            }
        };

        $group = FilterableCheckboxGroup::new(['alpha', 'beta'])
            ->withMatcher($repeating)
            ->withFocus(true);

        $typed = self::pressed($group, new KeyMsg('a'));

        $frame = new Frame(30, 8);
        $typed->render($frame, Rect::fromSize(30, 8), new RenderContext(Theme::dark()));

        self::assertSame(1, substr_count(ScreenBuffer::fromFrame($frame)->plainText(), 'alpha'));
    }

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

    #[Test]
    public function twoOptionsReadingTheSameAreRefusedWhereTheyAreHandedIn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FilterableCheckboxGroup options must read differently; "Yes" is at 0 and 2');

        FilterableCheckboxGroup::new(['Yes', 'No', 'Yes']);
    }

    #[Test]
    public function escapeClearsATypedQueryAndBringsEveryOptionBack(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma'])->withFocus(true);

        $narrowed = self::pressed($group, new KeyMsg('b'));
        $cleared = self::pressed($narrowed, new KeyMsg(Key::Escape));

        self::assertStringNotContainsString('alpha', self::drawn($narrowed), 'the query is narrowing to beta');
        self::assertStringContainsString('alpha', self::drawn($cleared), 'and the escape puts the rest back');
    }

    private static function drawn(FilterableCheckboxGroup $group): string
    {
        $frame = new Frame(30, 8);
        $group->render($frame, Rect::fromSize(30, 8), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame)->plainText();
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
