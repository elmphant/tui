<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\ChordTracker;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChordTrackerTest extends TestCase
{
    #[Test]
    public function aKeyNoChordStartsWithPassesStraightThrough(): void
    {
        $key = new KeyMsg('x');

        self::assertSame([$key], (new ChordTracker())->feed($key, self::chords()));
    }

    #[Test]
    public function theFirstKeyOfAChordIsHeldBack(): void
    {
        self::assertSame([], (new ChordTracker())->feed(new KeyMsg('g'), self::chords()));
    }

    #[Test]
    public function theLastKeyOfAChordArrivesCarryingTheKeysBeforeIt(): void
    {
        $tracker = new ChordTracker();
        $first = new KeyMsg('g');
        $tracker->feed($first, self::chords());

        $keys = $tracker->feed(new KeyMsg('t'), self::chords());

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
        $tracker->feed($first, self::chords());

        self::assertSame([$first, $breaking], $tracker->feed($breaking, self::chords()));
    }

    #[Test]
    public function aKeyThatBreaksAChordOffCanStartTheNextOne(): void
    {
        $tracker = new ChordTracker();
        $first = new KeyMsg('z');
        $tracker->feed($first, self::chords());

        self::assertSame([$first], $tracker->feed(new KeyMsg('g'), self::chords()));
        self::assertSame('g', $tracker->feed(new KeyMsg('g'), self::chords())[0]->key);
    }

    #[Test]
    public function stepsCanCarryModifiers(): void
    {
        $tracker = new ChordTracker();
        $chords = new Keybindings(['save' => ['ctrl+x ctrl+s']]);
        $tracker->feed(new KeyMsg('x', ctrl: true), $chords);

        $keys = $tracker->feed(new KeyMsg('s', ctrl: true), $chords);

        self::assertTrue($chords->matches($keys[0], 'save'));
    }

    private static function chords(): Keybindings
    {
        return new Keybindings(['top' => ['g g'], 'next' => ['g t'], 'fold' => ['z a']]);
    }
}
