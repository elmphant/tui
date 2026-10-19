<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\ChordTracker;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChordTrackerTest extends TestCase
{
    #[Test]
    public function aKeyNoChordStartsWithPassesStraightThrough(): void
    {
        $key = new KeyMsg('x');

        self::assertSame([$key], (new ChordTracker())->feed($key, self::chords(), 0.0));
    }

    #[Test]
    public function theFirstKeyOfAChordIsHeldBack(): void
    {
        self::assertSame([], (new ChordTracker())->feed(new KeyMsg('g'), self::chords(), 0.0));
    }

    #[Test]
    public function theLastKeyOfAChordArrivesCarryingTheKeysBeforeIt(): void
    {
        $tracker = new ChordTracker();
        $first = new KeyMsg('g');
        $tracker->feed($first, self::chords(), 0.0);

        $keys = $tracker->feed(new KeyMsg('t'), self::chords(), 0.0);

        self::assertCount(1, $keys);
        self::assertSame('t', $keys[0]->key);
        self::assertSame([$first], $keys[0]->preceding);
    }

    #[Test]
    public function aKeyThatBreaksAChordOffComesAfterTheHeldKeys(): void
    {
        $tracker = new ChordTracker();
        $first = new KeyMsg('g');
        $breaking = new KeyMsg('x');
        $tracker->feed($first, self::chords(), 0.0);

        self::assertSame([$first, $breaking], $tracker->feed($breaking, self::chords(), 0.0));
    }

    #[Test]
    public function aKeyThatBreaksAChordOffCanStartTheNextOne(): void
    {
        $tracker = new ChordTracker();
        $first = new KeyMsg('z');
        $tracker->feed($first, self::chords(), 0.0);

        self::assertSame([$first], $tracker->feed(new KeyMsg('g'), self::chords(), 0.0));
        self::assertSame('g', $tracker->feed(new KeyMsg('g'), self::chords(), 0.0)[0]->key);
    }

    #[Test]
    public function stepsCanCarryModifiers(): void
    {
        $tracker = new ChordTracker();
        $chords = new Keybindings(['save' => ['ctrl+x ctrl+s']]);
        $tracker->feed(new KeyMsg('x', ctrl: true), $chords, 0.0);

        $keys = $tracker->feed(new KeyMsg('s', ctrl: true), $chords, 0.0);

        self::assertTrue($chords->matches($keys[0], 'save'));
    }

    #[Test]
    public function theHeldKeysExpireOnceTheTimeoutHasPassed(): void
    {
        $tracker = new ChordTracker(0.5);
        $first = new KeyMsg('g');
        $tracker->feed($first, self::chords(), 10.0);

        self::assertSame([], $tracker->expired(10.4));
        self::assertSame([$first], $tracker->expired(10.5));
        self::assertNull($tracker->deadline());
    }

    #[Test]
    public function escapeCancelsAnOpenChordAndIsHandledNowhere(): void
    {
        $tracker = new ChordTracker();
        $tracker->feed(new KeyMsg('g'), self::chords(), 0.0);

        self::assertSame([], $tracker->feed(new KeyMsg(Key::Escape), self::chords(), 0.0));
        self::assertNull($tracker->deadline());
    }

    private static function chords(): Keybindings
    {
        return new Keybindings(['top' => ['g g'], 'next' => ['g t'], 'fold' => ['z a']]);
    }
}
