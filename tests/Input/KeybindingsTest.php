<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Gesture;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeyHint;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeybindingsTest extends TestCase
{
    #[Test]
    public function emptyHasNoActions(): void
    {
        $bindings = Keybindings::empty();

        self::assertSame([], $bindings->actions());
    }

    #[Test]
    public function emptyIsCachedSingleton(): void
    {
        self::assertSame(Keybindings::empty(), Keybindings::empty());
    }

    #[Test]
    public function getUnknownActionReturnsEmptyList(): void
    {
        $bindings = Keybindings::empty();

        self::assertSame([], $bindings->patternsOf('unknown'));
    }

    #[Test]
    public function constructParsesStringPatterns(): void
    {
        $bindings = new Keybindings(['quit' => ['q', 'ctrl+c']]);

        $patterns = $bindings->patternsOf('quit');

        self::assertCount(2, $patterns);
        self::assertEquals(KeyPattern::fromString('q'), $patterns[0]);
        self::assertEquals(KeyPattern::fromString('ctrl+c'), $patterns[1]);
    }

    #[Test]
    public function actionsReturnsAllActionKeys(): void
    {
        $bindings = new Keybindings([
            'quit' => ['q'],
            'submit' => ['enter'],
        ]);

        self::assertSame(['quit', 'submit'], $bindings->actions());
    }

    #[Test]
    public function constructWrapsParserErrorWithActionName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Invalid pattern for action 'quit'.*Unknown modifier 'hyper'/");

        new Keybindings(['quit' => ['hyper+a']]);
    }

    #[Test]
    public function constructWrapsParserErrorIncludesOriginalMessage(): void
    {
        try {
            new Keybindings(['submit' => ['ctrl+']]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("action 'submit'", $exception->getMessage());
            self::assertStringContainsString('Empty key', $exception->getMessage());
        }
    }

    #[Test]
    public function constructValidActionsBeforeInvalidOneDoNotAffectError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/action 'bad'/");

        new Keybindings([
            'quit' => ['q'],
            'bad' => ['hyper+a'],
        ]);
    }

    #[Test]
    public function matchesKnownActionWithMatchingKey(): void
    {
        $bindings = new Keybindings(['quit' => ['q', 'ctrl+c']]);

        self::assertTrue($bindings->matches(new KeyMsg('q'), 'quit'));
        self::assertTrue($bindings->matches(new KeyMsg('c', ctrl: true), 'quit'));
    }

    #[Test]
    public function matchesKnownActionWithNonMatchingKeyReturnsFalse(): void
    {
        $bindings = new Keybindings(['quit' => ['q']]);

        self::assertFalse($bindings->matches(new KeyMsg('x'), 'quit'));
    }

    #[Test]
    public function matchesUnknownActionReturnsFalse(): void
    {
        $bindings = new Keybindings(['quit' => ['q']]);

        self::assertFalse($bindings->matches(new KeyMsg('q'), 'typo_action'));
    }

    #[Test]
    public function matchesEmptyPatternListReturnsFalse(): void
    {
        $bindings = new Keybindings(['quit' => []]);

        self::assertFalse($bindings->matches(new KeyMsg('q'), 'quit'));
    }

    #[Test]
    public function constructAcceptsMixOfStringsAndKeyPatternInstances(): void
    {
        $preBuilt = new KeyPattern(key: 'c', ctrl: true);

        $bindings = new Keybindings(['quit' => ['q', $preBuilt]]);

        $patterns = $bindings->patternsOf('quit');
        self::assertCount(2, $patterns);
        self::assertSame($preBuilt, $patterns[1]);
    }

    #[Test]
    public function constructContinuesProcessingAfterKeyPatternInstance(): void
    {
        // When a KeyPattern instance appears before a string in the list, the
        // loop must continue (not break) so the subsequent string is still
        // parsed and appended.
        $preBuilt = new KeyPattern(key: 'c', ctrl: true);

        $bindings = new Keybindings(['quit' => [$preBuilt, 'q']]);

        $patterns = $bindings->patternsOf('quit');
        self::assertCount(2, $patterns);
        self::assertSame($preBuilt, $patterns[0]);
        self::assertEquals(KeyPattern::fromString('q'), $patterns[1]);
    }

    #[Test]
    public function mergeOverridesActionEntirely(): void
    {
        $defaults = new Keybindings([
            'quit' => ['q', 'ctrl+c'],
            'help' => ['?'],
        ]);
        $custom = new Keybindings([
            'quit' => ['ctrl+q'],
        ]);

        $merged = $defaults->overriddenBy($custom);

        // quit replaced entirely by custom
        self::assertTrue($merged->matches(new KeyMsg('q', ctrl: true), 'quit'));
        self::assertFalse($merged->matches(new KeyMsg('q'), 'quit'));
        self::assertFalse($merged->matches(new KeyMsg('c', ctrl: true), 'quit'));

        // help preserved from defaults
        self::assertTrue($merged->matches(new KeyMsg('?'), 'help'));
    }

    #[Test]
    public function mergeWithEmptyActionListUnbinds(): void
    {
        $defaults = new Keybindings(['quit' => ['q']]);
        $override = new Keybindings(['quit' => []]);

        $merged = $defaults->overriddenBy($override);

        self::assertFalse($merged->matches(new KeyMsg('q'), 'quit'));
    }

    #[Test]
    public function mergeWithEmptyIsIdentityInBehavior(): void
    {
        $defaults = new Keybindings(['quit' => ['q']]);

        $merged = $defaults->overriddenBy(Keybindings::empty());

        self::assertTrue($merged->matches(new KeyMsg('q'), 'quit'));
    }

    #[Test]
    public function mergeAddsNewActions(): void
    {
        $defaults = new Keybindings(['quit' => ['q']]);
        $extra = new Keybindings(['submit' => ['enter']]);

        $merged = $defaults->overriddenBy($extra);

        self::assertTrue($merged->matches(new KeyMsg('q'), 'quit'));

        // submit action added from extra
        $enterKey = Key::Enter;
        self::assertTrue($merged->matches(new KeyMsg($enterKey), 'submit'));
    }

    #[Test]
    public function appendAddsPatternsToExistingAction(): void
    {
        $defaults = new Keybindings(['cursor_down' => ['down']]);
        $extra = new Keybindings(['cursor_down' => ['j']]);

        $merged = $defaults->plus($extra);

        self::assertTrue($merged->matches(new KeyMsg(Key::Down), 'cursor_down'));
        self::assertTrue($merged->matches(new KeyMsg('j'), 'cursor_down'));
    }

    #[Test]
    public function appendAddsNewAction(): void
    {
        $defaults = new Keybindings(['cursor_down' => ['down']]);
        $extra = new Keybindings(['help' => ['?']]);

        $merged = $defaults->plus($extra);

        self::assertTrue($merged->matches(new KeyMsg('?'), 'help'));
        self::assertTrue($merged->matches(new KeyMsg(Key::Down), 'cursor_down'));
    }

    #[Test]
    public function appendDeduplicatesByCanonicalForm(): void
    {
        $defaults = new Keybindings(['cursor_down' => ['j', 'down']]);
        $extra = new Keybindings(['cursor_down' => ['j']]);   // duplicate

        $merged = $defaults->plus($extra);

        self::assertCount(2, $merged->patternsOf('cursor_down'));
    }

    #[Test]
    public function appendEmptyIsIdentity(): void
    {
        $defaults = new Keybindings(['cursor_down' => ['down', 'j']]);

        $merged = $defaults->plus(Keybindings::empty());

        self::assertTrue($merged->matches(new KeyMsg('j'), 'cursor_down'));
        self::assertTrue($merged->matches(new KeyMsg(Key::Down), 'cursor_down'));
    }

    #[Test]
    public function appendDoesNotRemoveDefaults(): void
    {
        $defaults = new Keybindings(['cursor_down' => ['down']]);
        $extra = new Keybindings(['cursor_down' => ['j']]);

        $merged = $defaults->plus($extra);

        self::assertTrue($merged->matches(new KeyMsg(Key::Down), 'cursor_down'));
        self::assertTrue($merged->matches(new KeyMsg('j'), 'cursor_down'));
    }

    #[Test]
    public function hintsNameTheKeyEachActionLeadsWith(): void
    {
        $bindings = new Keybindings([
            'quit' => ['ctrl+q'],
            'navigate' => ['down', 'j'],
        ]);

        self::assertSame(
            ['Ctrl+Q' => 'quit', '↓' => 'move down'],
            $bindings->hints(['quit' => 'quit', 'move down' => 'navigate']),
        );
    }

    #[Test]
    public function severalActionsUnderOneDescriptionShareOneLabel(): void
    {
        $bindings = new Keybindings([
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_DOWN => ['down'],
        ]);

        self::assertSame(
            ['↑/↓' => 'move'],
            $bindings->hints(['move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN]]),
        );
    }

    #[Test]
    public function aModifierSharedByEveryKeyOfAHintIsWrittenOnce(): void
    {
        $bindings = new Keybindings([
            Actions::TAB_PREVIOUS => ['shift+left'],
            Actions::TAB_NEXT => ['shift+right'],
        ]);

        self::assertSame(
            ['Shift+←/→' => 'switch tab'],
            $bindings->hints(['switch tab' => [Actions::TAB_PREVIOUS, Actions::TAB_NEXT]]),
        );
    }

    #[Test]
    public function aModifierThatOnlySomeKeysOfAHintTakeIsWrittenAsOptional(): void
    {
        $bindings = Keybindings::rowNavigation();

        self::assertSame(
            ['(Shift+)↑/↓' => 'select'],
            $bindings->hints(['select' => [
                Actions::CURSOR_UP,
                Actions::CURSOR_DOWN,
                Actions::PAGE_UP,
                Actions::PAGE_DOWN,
            ]]),
        );
    }

    #[Test]
    public function keysWithNoModifierInCommonAreBothSpeltOut(): void
    {
        $bindings = new Keybindings([
            Actions::CLEAR_LINE => ['ctrl+u'],
            Actions::CANCEL => ['esc'],
        ]);

        self::assertSame(
            ['Ctrl+U/Esc' => 'clear search'],
            $bindings->hints(['clear search' => [Actions::CLEAR_LINE, Actions::CANCEL]]),
        );
    }

    #[Test]
    public function hintsSkipsUnboundActionsAndKeepsLabelOrder(): void
    {
        $bindings = new Keybindings(['quit' => ['ctrl+q'], 'help' => ['f1']]);

        self::assertSame(
            ['F1' => 'help', 'Ctrl+Q' => 'quit'],
            $bindings->hints(['help' => 'help', 'search' => 'search', 'quit' => 'quit']),
        );
    }

    #[Test]
    public function theKeysOfSeveralActionsReadAsOneLabelForProse(): void
    {
        $bindings = new Keybindings([
            Actions::TAB_PREVIOUS => ['shift+left'],
            Actions::TAB_NEXT => ['shift+right'],
        ]);

        self::assertSame('Shift+←/→', $bindings->label(Actions::TAB_PREVIOUS, Actions::TAB_NEXT));
    }

    #[Test]
    public function aChordThatStartsWithTextIsLeftOutForAFieldAndAModifiedOneIsKept(): void
    {
        $bindings = (new Keybindings(['top' => ['g g'], 'save' => ['ctrl+x ctrl+s']]))->withoutText();

        self::assertSame([], $bindings->patternsOf('top'));
        self::assertSame('ctrl+x ctrl+s', (string)$bindings->patternsOf('save')[0]);
    }

    #[Test]
    public function actionsWithoutAKeyEachHaveNoLabel(): void
    {
        $bindings = new Keybindings([Actions::TAB_NEXT => ['shift+right']]);

        self::assertNull($bindings->label(Actions::TAB_PREVIOUS, Actions::TAB_NEXT));
    }

    #[Test]
    public function aKeyHintCarriesTheKeyItsActionLeadsWithAndAGestureCarriesNone(): void
    {
        $bindings = new Keybindings(['refresh' => ['r', 'x'], 'reload' => ['ctrl+r'], 'unbound' => []]);

        self::assertEquals(
            [
                new KeyHint('r/Ctrl+R', 'refresh', KeyPattern::fromString('r')),
                new KeyHint('Click', 'open'),
            ],
            $bindings->keyHints(['refresh' => ['refresh', 'reload'], 'open' => Gesture::Click, 'nothing' => 'unbound']),
        );
        self::assertSame(['r/Ctrl+R' => 'refresh', 'Click' => 'open'], $bindings->hints(['refresh' => ['refresh', 'reload'], 'open' => Gesture::Click, 'nothing' => 'unbound']));
    }
}
