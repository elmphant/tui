<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Always match the last entry so tests can distinguish the custom matcher from substring matching.
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
    public function aPasteFiltersLikeTyping(): void
    {
        $pasted = self::pressed(FilterableCheckboxGroup::new(['alpha', 'beta']), new PasteMsg('be'));

        $frame = new Frame(30, 8);
        $pasted->render($frame, Rect::fromSize(30, 8), new RenderContext(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame)->plainText();

        self::assertSame([true, false], [str_contains($screen, 'beta'), str_contains($screen, 'alpha')]);
    }

    #[Test]
    public function aGroupWithSomethingTypedInItNamesTheKeyThatClearsIt(): void
    {
        $typed = self::pressed(
            FilterableCheckboxGroup::new(['alpha', 'beta']),
            new KeyMsg('a'),
        );

        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'toggle', 'Esc' => 'clear filter'],
            $typed->hints(),
        );
    }

    #[Test]
    public function aGlobalLetterIsTypedIntoTheFieldOnceSlashOpensIt(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'jam'])
            ->withGlobalKeybindings(VimMode::keybindings());

        $ticked = self::pressed($group, new KeyMsg('/'), new KeyMsg('j'), new KeyMsg('enter'), new KeyMsg(' '));

        self::assertSame(['jam'], $ticked->checkedValues());
    }

    #[Test]
    public function aGlobalChordWalksTheBoxes(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta'])
            ->withGlobalKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['ctrl+n']]));

        $ticked = self::pressed($group, new KeyMsg('n', ctrl: true), new KeyMsg(' '));

        self::assertSame(['beta'], $ticked->checkedValues());
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
            ->withMatcher($repeating);

        $typed = self::pressed($group, new KeyMsg('a'));

        $frame = new Frame(30, 8);
        $typed->render($frame, Rect::fromSize(30, 8), new RenderContext(Theme::dark()));

        self::assertSame(1, substr_count(ScreenBuffer::fromFrame($frame)->plainText(), 'alpha'));
    }

    #[Test]
    public function aMatcherNamingAnIndexBeyondTheOptionsDrawsNoGhostBoxForIt(): void
    {
        $stale = new class implements Matcher {
            /**
             * @param list<string> $haystack
             *
             * @return list<int>
             */
            public function matching(string $query, array $haystack): array
            {
                return $haystack === [] ? [] : [0, 9];
            }
        };

        $group = FilterableCheckboxGroup::new(['alpha', 'beta'])
            ->withMatcher($stale);

        $typed = self::pressed($group, new KeyMsg('a'));

        $frame = new Frame(30, 8);
        $typed->render($frame, Rect::fromSize(30, 8), new RenderContext(Theme::dark()));

        self::assertSame(
            1,
            substr_count(ScreenBuffer::fromFrame($frame)->plainText(), '[ ]'),
            'the stale index draws nothing — an untogglable empty box is not an option',
        );
    }

    #[Test]
    public function narrowsTheBoxesWithTheMatcherItWasGiven(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma'])
            ->withMatcher(new LastOptionOnlyMatcher());

        $ticked = self::pressed($group, new KeyMsg('z'), new KeyMsg(' '));

        self::assertSame(['gamma'], $ticked->checkedValues(), 'The supplied matcher decides which boxes a query leaves');
    }

    #[Test]
    public function aBlankQueryLeavesEveryBoxWhateverTheMatcher(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma'])
            ->withMatcher(new LastOptionOnlyMatcher());

        $blank = self::pressed($group, new PasteMsg('  '));

        self::assertSame(1 + 1 + 3, $blank->popupHeight(40), 'the field, the gap and all three boxes');
    }

    #[Test]
    public function aTickOutlivesTheQueryThatWasNarrowingTheBoxesWhenItWasMade(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail']);

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
    public function aSecondOptionActivatedJoinsTheFirst(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma']);

        self::assertSame(['alpha', 'gamma'], $group->activated('alpha')->activated('gamma')->checkedValues());
    }

    #[Test]
    public function aVisibleTickCanBeTakenBackWhileAHiddenOneStays(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log'])->activated('sync_log');

        $toggledTwice = self::pressed($group, new KeyMsg('a'), new KeyMsg('p'), new KeyMsg(' '), new KeyMsg(' '));

        self::assertSame(['sync_log'], $toggledTwice->checkedValues());
    }

    #[Test]
    public function escapePutsTheCursorBackOnTheFirstOption(): void
    {
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma']);

        $ticked = self::pressed($group, new KeyMsg('a'), new KeyMsg(Key::Down), new KeyMsg(Key::Down), new KeyMsg(Key::Escape), new KeyMsg(' '));

        self::assertSame(['alpha'], $ticked->checkedValues());
    }

    #[Test]
    public function aMatcherThatNarrowsBelowTheCursorLeavesItOnTheLastBox(): void
    {
        $group = self::pressed(FilterableCheckboxGroup::new(['alpha', 'beta', 'delta']), new KeyMsg('a'), new KeyMsg(Key::Down), new KeyMsg(Key::Down));

        $ticked = self::pressed($group->withMatcher(new LastOptionOnlyMatcher()), new KeyMsg(' '));

        self::assertSame(['delta'], $ticked->checkedValues());
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
        $group = FilterableCheckboxGroup::new(['alpha', 'beta', 'gamma']);

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
