<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyPatternTest extends TestCase
{
    #[Test]
    public function constructStoresFields(): void
    {
        $pattern = new KeyPattern(key: 'q', ctrl: true);

        self::assertSame('q', $pattern->key);
        self::assertTrue($pattern->ctrl);
        self::assertFalse($pattern->alt);
        self::assertFalse($pattern->shift);
    }

    #[Test]
    public function everyKeyIsNamedSoThePatternParserReadsItBack(): void
    {
        foreach ([...Key::cases(), ' ', 'x'] as $key) {
            self::assertSame($key, KeyPattern::fromString(KeyPattern::nameOf($key))->key);
        }
    }

    #[Test]
    public function fromStringParsesSingleChar(): void
    {
        $pattern = KeyPattern::fromString('q');

        self::assertSame('q', $pattern->key);
        self::assertFalse($pattern->ctrl);
        self::assertFalse($pattern->alt);
        self::assertFalse($pattern->shift);
    }

    public static function namedKeyProvider(): iterable
    {
        yield 'enter' => ['enter',        new KeyPattern(key: Key::Enter)];
        yield 'return alias' => ['return',       new KeyPattern(key: Key::Enter)];
        yield 'esc' => ['esc',          new KeyPattern(key: Key::Escape)];
        yield 'escape' => ['escape',       new KeyPattern(key: Key::Escape)];
        yield 'tab' => ['tab',          new KeyPattern(key: Key::Tab)];
        yield 'backspace' => ['backspace',    new KeyPattern(key: Key::Backspace)];
        yield 'bs alias' => ['bs',           new KeyPattern(key: Key::Backspace)];
        yield 'delete' => ['delete',       new KeyPattern(key: Key::Delete)];
        yield 'del alias' => ['del',          new KeyPattern(key: Key::Delete)];
        yield 'insert' => ['insert',       new KeyPattern(key: Key::Insert)];
        yield 'ins alias' => ['ins',          new KeyPattern(key: Key::Insert)];
        yield 'up' => ['up',           new KeyPattern(key: Key::Up)];
        yield 'down' => ['down',         new KeyPattern(key: Key::Down)];
        yield 'left' => ['left',         new KeyPattern(key: Key::Left)];
        yield 'right' => ['right',        new KeyPattern(key: Key::Right)];
        yield 'home' => ['home',         new KeyPattern(key: Key::Home)];
        yield 'end' => ['end',          new KeyPattern(key: Key::End)];
        yield 'page_up' => ['page_up',      new KeyPattern(key: Key::PageUp)];
        yield 'pgup' => ['pgup',         new KeyPattern(key: Key::PageUp)];
        yield 'pageup' => ['pageup',       new KeyPattern(key: Key::PageUp)];
        yield 'page_down' => ['page_down',    new KeyPattern(key: Key::PageDown)];
        yield 'pgdn' => ['pgdn',         new KeyPattern(key: Key::PageDown)];
        yield 'pagedown' => ['pagedown',     new KeyPattern(key: Key::PageDown)];
        yield 'f1' => ['f1',           new KeyPattern(key: Key::F1)];
        yield 'f2' => ['f2',           new KeyPattern(key: Key::F2)];
        yield 'f3' => ['f3',           new KeyPattern(key: Key::F3)];
        yield 'f4' => ['f4',           new KeyPattern(key: Key::F4)];
        yield 'f5' => ['f5',           new KeyPattern(key: Key::F5)];
        yield 'f6' => ['f6',           new KeyPattern(key: Key::F6)];
        yield 'f7' => ['f7',           new KeyPattern(key: Key::F7)];
        yield 'f8' => ['f8',           new KeyPattern(key: Key::F8)];
        yield 'f9' => ['f9',           new KeyPattern(key: Key::F9)];
        yield 'f10' => ['f10',          new KeyPattern(key: Key::F10)];
        yield 'f11' => ['f11',          new KeyPattern(key: Key::F11)];
        yield 'f12' => ['f12',          new KeyPattern(key: Key::F12)];
        yield 'space' => ['space',        new KeyPattern(key: ' ')];
        yield 'digit' => ['5',            new KeyPattern(key: '5')];
        yield 'char a' => ['a',            new KeyPattern(key: 'a')];
    }

    #[DataProvider('namedKeyProvider')]
    #[Test]
    public function fromStringParsesNamedKeys(string $input, KeyPattern $expected): void
    {
        self::assertEquals($expected, KeyPattern::fromString($input));
    }

    public static function modifierProvider(): iterable
    {
        yield 'ctrl+c' => ['ctrl+c',            new KeyPattern(key: 'c', ctrl: true)];
        yield 'alt+x' => ['alt+x',             new KeyPattern(key: 'x', alt: true)];
        yield 'shift+tab' => ['shift+tab',         new KeyPattern(key: Key::Tab, shift: true)];
        yield 'ctrl+enter' => ['ctrl+enter',        new KeyPattern(key: Key::Enter, ctrl: true)];
        yield 'ctrl+alt+k' => ['ctrl+alt+k',        new KeyPattern(key: 'k', ctrl: true, alt: true)];
        yield 'ctrl+shift+a' => ['ctrl+shift+a',      new KeyPattern(key: 'a', ctrl: true, shift: true)];
        yield 'ctrl+alt+shift+a' => ['ctrl+alt+shift+a',  new KeyPattern(key: 'a', ctrl: true, alt: true, shift: true)];
        yield 'free order 1' => ['alt+ctrl+k',        new KeyPattern(key: 'k', ctrl: true, alt: true)];
        yield 'free order 2' => ['shift+ctrl+alt+a', new KeyPattern(key: 'a', ctrl: true, alt: true, shift: true)];
        yield 'plus' => ['+',                 new KeyPattern(key: '+')];
    }

    #[DataProvider('modifierProvider')]
    #[Test]
    public function fromStringParsesModifiers(string $input, KeyPattern $expected): void
    {
        self::assertEquals($expected, KeyPattern::fromString($input));
    }

    public static function caseAndWhitespaceProvider(): iterable
    {
        yield 'uppercase Ctrl' => ['Ctrl+c',     new KeyPattern(key: 'c', ctrl: true)];
        yield 'all upper' => ['CTRL+C',     new KeyPattern(key: 'c', ctrl: true)];
        yield 'spaces around' => ['ctrl + c',   new KeyPattern(key: 'c', ctrl: true)];
        yield 'CTRL+ALT+k' => ['CTRL+ALT+k', new KeyPattern(key: 'k', ctrl: true, alt: true)];
        yield 'named upper' => ['SHIFT+TAB',  new KeyPattern(key: Key::Tab, shift: true)];
    }

    #[DataProvider('caseAndWhitespaceProvider')]
    #[Test]
    public function fromStringIsCaseInsensitiveAndTrimsWhitespace(string $input, KeyPattern $expected): void
    {
        self::assertEquals($expected, KeyPattern::fromString($input));
    }

    #[Test]
    public function shiftedLetterNormalization(): void
    {
        self::assertEquals(
            KeyPattern::fromString('shift+a'),
            KeyPattern::fromString('A'),
        );
    }

    #[Test]
    public function shiftedLetterFieldsStored(): void
    {
        $pattern = KeyPattern::fromString('A');

        self::assertSame('a', $pattern->key);
        self::assertTrue($pattern->shift);
        self::assertFalse($pattern->ctrl);
        self::assertFalse($pattern->alt);
    }

    #[Test]
    public function shiftedLetterUpperBoundZIsNormalized(): void
    {
        $pattern = KeyPattern::fromString('Z');

        self::assertSame('z', $pattern->key);
        self::assertTrue($pattern->shift);
    }

    #[Test]
    public function shiftedLetterNormalizationTrimsRawTokenWhitespace(): void
    {
        // With whitespace around uppercase 'A' (" A "), the normalization path
        // must trim the ORIGINAL raw token to correctly detect the single
        // uppercase letter and add implicit shift.
        $pattern = KeyPattern::fromString(' A ');

        self::assertSame('a', $pattern->key);
        self::assertTrue($pattern->shift);
    }

    #[Test]
    public function caseInsensitiveWithModifiersDoesNotAddImplicitShift(): void
    {
        self::assertEquals(
            KeyPattern::fromString('ctrl+c'),
            KeyPattern::fromString('Ctrl+C'),
        );

        $pattern = KeyPattern::fromString('Ctrl+C');
        self::assertSame('c', $pattern->key);
        self::assertTrue($pattern->ctrl);
        self::assertFalse($pattern->shift);
    }

    #[Test]
    public function ctrlPlusUppercaseLetterIsAcceptedAsCaseInsensitive(): void
    {
        // Spec: uppercase letter with a non-shift modifier is case-insensitive,
        // NOT rejected. "ctrl+A" is equivalent to "ctrl+a" (no implicit shift).
        self::assertEquals(
            KeyPattern::fromString('ctrl+a'),
            KeyPattern::fromString('ctrl+A'),
        );

        $pattern = KeyPattern::fromString('ctrl+A');
        self::assertSame('a', $pattern->key);
        self::assertTrue($pattern->ctrl);
        self::assertFalse($pattern->shift);
    }

    #[Test]
    public function altPlusUppercaseLetterIsAcceptedAsCaseInsensitive(): void
    {
        self::assertEquals(
            KeyPattern::fromString('alt+x'),
            KeyPattern::fromString('alt+X'),
        );
    }

    #[Test]
    public function aModifiedPlusIsBoundByDoublingThePlus(): void
    {
        self::assertEquals(new KeyPattern(key: '+', ctrl: true), KeyPattern::fromString('ctrl++'));
        self::assertEquals(new KeyPattern(key: '+', ctrl: true, alt: true), KeyPattern::fromString('ctrl+alt++'));
    }

    #[Test]
    public function digitWithShiftPreserved(): void
    {
        $pattern = KeyPattern::fromString('shift+9');

        self::assertSame('9', $pattern->key);
        self::assertTrue($pattern->shift);
    }

    public static function invalidPatternProvider(): iterable
    {
        yield 'empty' => ['',              'Empty pattern'];
        yield 'trailing plus' => ['ctrl+',         'Empty key'];
        yield 'leading plus' => ['+c',            'Empty modifier'];
        yield 'only modifier' => ['ctrl',          'no key'];
        yield 'duplicate modifier' => ['ctrl+ctrl+a',   'Duplicate modifier'];
        yield 'unknown modifier' => ['hyper+a',       'Unknown modifier'];
        yield 'super rejected' => ['super+a',       'Unknown modifier'];
        yield 'cmd rejected' => ['cmd+a',         'Unknown modifier'];
        yield 'meta rejected' => ['meta+a',        'Unknown modifier'];
        yield 'unknown key' => ['ctrl+nope',     'Unknown key'];
        yield 'multi-char key' => ['ab',            'Multi-character'];
        yield 'multi-char with mod' => ['ctrl+ab',       'Multi-character'];
    }

    #[DataProvider('invalidPatternProvider')]
    #[Test]
    public function fromStringThrowsOnInvalid(string $input, string $messageSubstring): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messageSubstring, '/') . '/');

        KeyPattern::fromString($input);
    }

    #[Test]
    public function matchesReturnsTrueOnFieldEquality(): void
    {
        $pattern = KeyPattern::fromString('ctrl+c');

        self::assertTrue($pattern->matches(new KeyMsg('c', ctrl: true)));
    }

    #[Test]
    public function matchesReturnsFalseOnDifferentKey(): void
    {
        $pattern = KeyPattern::fromString('ctrl+c');

        self::assertFalse($pattern->matches(new KeyMsg('d', ctrl: true)));
    }

    #[Test]
    public function matchesReturnsFalseOnMissingModifier(): void
    {
        $pattern = KeyPattern::fromString('ctrl+c');

        self::assertFalse($pattern->matches(new KeyMsg('c')));
    }

    #[Test]
    public function matchesReturnsFalseOnExtraModifier(): void
    {
        $pattern = KeyPattern::fromString('ctrl+c');

        self::assertFalse($pattern->matches(new KeyMsg('c', ctrl: true, alt: true)));
    }

    #[Test]
    public function matchesReturnsFalseForReleaseEvent(): void
    {
        $pattern = KeyPattern::fromString('ctrl+c');

        self::assertFalse($pattern->matches(
            new KeyMsg('c', ctrl: true, eventType: KeyEventType::Release),
        ));
    }

    #[Test]
    public function matchesReturnsTrueForRepeatEvent(): void
    {
        $pattern = KeyPattern::fromString('ctrl+c');

        self::assertTrue($pattern->matches(
            new KeyMsg('c', ctrl: true, eventType: KeyEventType::Repeat),
        ));
    }

    #[Test]
    public function matchesAsciiUppercaseAgainstShiftLowercase(): void
    {
        $pattern = KeyPattern::fromString('shift+a');

        // Legacy ASCII: Shift+A arrives as KeyMsg(key: 'A', shift: false)
        self::assertTrue($pattern->matches(new KeyMsg('A')));
    }

    #[Test]
    public function matchesKittyLowercasePlusShift(): void
    {
        $pattern = KeyPattern::fromString('A');  // stored as (key:'a', shift:true)

        // Kitty protocol: Shift+A arrives as KeyMsg(key: 'a', shift: true)
        self::assertTrue($pattern->matches(new KeyMsg('a', shift: true)));
    }

    #[Test]
    public function matchesAsciiUppercaseWithUppercasePattern(): void
    {
        $pattern = KeyPattern::fromString('A');

        self::assertTrue($pattern->matches(new KeyMsg('A')));
    }

    #[Test]
    public function matchesUppercaseBoundaryZ(): void
    {
        // Guard the upper end of the A-Z range in matches(): 'Z' must be
        // lowercased with shift inferred and match a Z pattern.
        $pattern = KeyPattern::fromString('Z');

        self::assertTrue($pattern->matches(new KeyMsg('Z')));
    }

    public static function canonicalFormProvider(): iterable
    {
        yield 'plain char' => ['q',                'q'];
        yield 'ctrl+char' => ['ctrl+c',           'ctrl+c'];
        yield 'alt+ctrl reorder' => ['alt+ctrl+k',       'ctrl+alt+k'];
        yield 'all three reorder' => ['shift+ctrl+alt+a', 'ctrl+alt+shift+a'];
        yield 'named key' => ['enter',            'enter'];
        yield 'return alias' => ['return',           'enter'];
        yield 'bare upper' => ['A',                'shift+a'];
        yield 'Ctrl+C upper' => ['Ctrl+C',           'ctrl+c'];
        yield 'shift+tab' => ['shift+tab',        'shift+tab'];
    }

    #[DataProvider('canonicalFormProvider')]
    #[Test]
    public function toStringCanonicalForm(string $input, string $expectedCanonical): void
    {
        self::assertSame($expectedCanonical, (string)KeyPattern::fromString($input));
    }

    #[Test]
    public function roundTrip(): void
    {
        $pattern = KeyPattern::fromString('ctrl+alt+shift+a');
        $roundTrip = KeyPattern::fromString((string)$pattern);

        self::assertEquals($pattern, $roundTrip);
    }

    #[Test]
    public function fromKeyMsgNormalizesUppercaseLetter(): void
    {
        self::assertEquals(
            KeyPattern::fromString('shift+a'),
            KeyPattern::fromKeyMsg(new KeyMsg('A')),
        );
    }

    #[Test]
    public function fromKeyMsgPreservesKittyLowercasePlusShift(): void
    {
        self::assertEquals(
            KeyPattern::fromString('shift+a'),
            KeyPattern::fromKeyMsg(new KeyMsg('a', shift: true)),
        );
    }

    #[Test]
    public function fromKeyMsgPreservesModifiers(): void
    {
        self::assertEquals(
            KeyPattern::fromString('ctrl+alt+k'),
            KeyPattern::fromKeyMsg(new KeyMsg('k', ctrl: true, alt: true)),
        );
    }

    #[Test]
    public function fromKeyMsgEnumKeyIsNotNormalized(): void
    {
        self::assertEquals(
            KeyPattern::fromString('home'),
            KeyPattern::fromKeyMsg(new KeyMsg(Key::Home)),
        );
    }

    #[Test]
    public function fromKeyMsgLowercaseLetterNotNormalized(): void
    {
        $pattern = KeyPattern::fromKeyMsg(new KeyMsg('a'));
        self::assertSame('a', $pattern->key);
        self::assertFalse($pattern->shift);
    }

    #[Test]
    public function fromKeyMsgDigitNotNormalized(): void
    {
        // Digits '0'-'9' are not uppercase letters A-Z.
        // Kills a LogicalAnd mutation that would spuriously normalize
        // any printable char between ASCII 48 and 90.
        $pattern = KeyPattern::fromKeyMsg(new KeyMsg('0'));
        self::assertSame('0', $pattern->key);
        self::assertFalse($pattern->shift);
    }

    #[Test]
    public function fromKeyMsgUppercaseZBoundary(): void
    {
        $pattern = KeyPattern::fromKeyMsg(new KeyMsg('Z'));
        self::assertSame('z', $pattern->key);
        self::assertTrue($pattern->shift);
    }

    #[Test]
    public function fromKeyMsgUppercaseABoundary(): void
    {
        $pattern = KeyPattern::fromKeyMsg(new KeyMsg('A'));
        self::assertSame('a', $pattern->key);
        self::assertTrue($pattern->shift);
    }

    #[Test]
    public function matchesRepeatEventsSoHeldKeysKeepFiring(): void
    {
        $pattern = KeyPattern::fromString('down');

        self::assertTrue($pattern->matches(new KeyMsg(
            Key::Down,
            eventType: KeyEventType::Repeat,
        )));
    }

    #[Test]
    public function doesNotMatchReleaseEvents(): void
    {
        $pattern = KeyPattern::fromString('down');

        self::assertFalse($pattern->matches(new KeyMsg(
            Key::Down,
            eventType: KeyEventType::Release,
        )));
    }

    #[Test]
    public function labelUppercasesLetterKeysWithModifiers(): void
    {
        self::assertSame('Ctrl+Q', KeyPattern::fromString('ctrl+q')->label());
    }

    #[Test]
    public function labelKeepsBareLetterKeysAsTyped(): void
    {
        self::assertSame('q', KeyPattern::fromString('q')->label());
    }

    #[Test]
    public function labelRendersArrowKeysAsSymbols(): void
    {
        self::assertSame('↑', KeyPattern::fromString('up')->label());
        self::assertSame('↓', KeyPattern::fromString('down')->label());
        self::assertSame('←', KeyPattern::fromString('left')->label());
        self::assertSame('→', KeyPattern::fromString('right')->label());
    }

    #[Test]
    public function labelRendersNamedKeys(): void
    {
        self::assertSame('Esc', KeyPattern::fromString('esc')->label());
        self::assertSame('Tab', KeyPattern::fromString('tab')->label());
        self::assertSame('Enter', KeyPattern::fromString('enter')->label());
        self::assertSame('PgUp', KeyPattern::fromString('pgup')->label());
        self::assertSame('PgDn', KeyPattern::fromString('pgdn')->label());
        self::assertSame('Space', KeyPattern::fromString('space')->label());
        self::assertSame('F5', KeyPattern::fromString('f5')->label());
    }

    #[Test]
    public function labelCombinesAllModifiersInStableOrder(): void
    {
        self::assertSame('Ctrl+Alt+Shift+X', KeyPattern::fromString('ctrl+alt+shift+x')->label());
        self::assertSame('Alt+↓', KeyPattern::fromString('alt+down')->label());
    }

    #[Test]
    public function aCapitalLetterIsLabelledAsItself(): void
    {
        self::assertSame('G', KeyPattern::fromString('G')->label());
        self::assertSame('G', KeyPattern::fromString('shift+g')->label());
        self::assertSame('Ctrl+Shift+G', KeyPattern::fromString('ctrl+shift+g')->label());
        self::assertSame('Shift+1', KeyPattern::fromString('shift+1')->label());
    }

    #[Test]
    public function aChordIsLabelledAsItsKeysAreTyped(): void
    {
        self::assertSame('gg', KeyPattern::fromString('g g')->label());
        self::assertSame('gT', KeyPattern::fromString('g T')->label());
        self::assertSame('Ctrl+X Ctrl+S', KeyPattern::fromString('ctrl+x ctrl+s')->label());
    }

    #[Test]
    public function chordsSharingAFirstKeyAreJoinedWhole(): void
    {
        self::assertSame('gt/gT', KeyPattern::joinedLabel(KeyPattern::fromString('g t'), KeyPattern::fromString('g T')));
        self::assertSame('H/L', KeyPattern::joinedLabel(KeyPattern::fromString('H'), KeyPattern::fromString('L')));
    }
}
