<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\ChordBindings;
use Elmphant\Tui\Input\ChordTracker;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChordTrackerTest extends TestCase
{
    #[Test]
    public function unboundKeyPassesThrough(): void
    {
        $tracker = new ChordTracker(ChordBindings::empty());

        $msg = new KeyMsg('x');
        self::assertSame($msg, $tracker->feed($msg, 0.0));
    }

    #[Test]
    public function bufferStartsEmpty(): void
    {
        $tracker = new ChordTracker(ChordBindings::empty());

        self::assertSame([], $tracker->buffer());
    }

    #[Test]
    public function completeMatchFiresAndClearsBuffer(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.0));
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.1));
        self::assertSame([], $tracker->buffer());
    }

    #[Test]
    public function prefixConsumesAndReturnsNull(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.0));
        self::assertEquals([KeyPattern::fromString('g')], $tracker->buffer());
    }

    #[Test]
    public function nonPrefixPassesThroughAndResets(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));
        $tracker->feed(new KeyMsg('g'), 0.0);

        $msg = new KeyMsg('x');
        self::assertSame($msg, $tracker->feed($msg, 0.1));
        self::assertSame([], $tracker->buffer());
    }

    #[Test]
    public function bufferResetsAfterPassthroughFreshStart(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));
        $tracker->feed(new KeyMsg('g'), 0.0);
        $tracker->feed(new KeyMsg('x'), 0.1);

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.2));
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.3));
    }

    #[Test]
    public function timeoutClearsStaleBufferBeforeNextFeed(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
            timeoutMs: 500,
        );

        $tracker->feed(new KeyMsg('g'), 0.0);
        $result = $tracker->feed(new KeyMsg('g'), 0.6);

        self::assertNull($result);
        self::assertEquals([KeyPattern::fromString('g')], $tracker->buffer());
    }

    #[Test]
    public function withinTimeoutCompletes(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
            timeoutMs: 500,
        );

        $tracker->feed(new KeyMsg('g'), 0.0);
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.4));
    }

    #[Test]
    public function customTimeoutRespected(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
            timeoutMs: 1000,
        );

        $tracker->feed(new KeyMsg('g'), 0.0);
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.9));
    }

    #[Test]
    public function idleGapTimeoutResetsOnEachStep(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['triple' => [['a', 'b', 'c']]]),
            timeoutMs: 500,
        );

        self::assertNull($tracker->feed(new KeyMsg('a'), 0.0));
        self::assertNull($tracker->feed(new KeyMsg('b'), 0.3));
        self::assertSame('triple', $tracker->feed(new KeyMsg('c'), 0.6));
    }

    #[Test]
    public function releaseEventPassesThroughWithoutModifyingBuffer(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
        );
        $tracker->feed(new KeyMsg('g'), 0.0);

        $releaseMsg = new KeyMsg('g', eventType: KeyEventType::Release);
        self::assertSame($releaseMsg, $tracker->feed($releaseMsg, 0.1));
        self::assertCount(1, $tracker->buffer());
    }

    #[Test]
    public function repeatEventPassesThroughWithoutModifyingBuffer(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
        );
        $tracker->feed(new KeyMsg('g'), 0.0);

        $repeatMsg = new KeyMsg('g', eventType: KeyEventType::Repeat);
        self::assertSame($repeatMsg, $tracker->feed($repeatMsg, 0.1));
        self::assertCount(1, $tracker->buffer());
    }

    #[Test]
    public function resetClearsBuffer(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
        );
        $tracker->feed(new KeyMsg('g'), 0.0);

        $tracker->reset();

        self::assertSame([], $tracker->buffer());
    }

    #[Test]
    public function resetAllowsFreshStart(): void
    {
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
        );
        $tracker->feed(new KeyMsg('g'), 0.0);
        $tracker->reset();

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.1));
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.2));
    }

    #[Test]
    public function completeBeatsPrefix(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'g_alone' => [['g']],
            'go_to_top' => [['g', 'g']],
        ]));

        self::assertSame('g_alone', $tracker->feed(new KeyMsg('g'), 0.0));
        self::assertSame([], $tracker->buffer());
    }

    #[Test]
    public function multipleSequencesForSameAction(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'home' => [['g', 'g'], ['h', 'o', 'm', 'e']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.0));
        self::assertSame('home', $tracker->feed(new KeyMsg('g'), 0.1));

        self::assertNull($tracker->feed(new KeyMsg('h'), 0.2));
        self::assertNull($tracker->feed(new KeyMsg('o'), 0.3));
        self::assertNull($tracker->feed(new KeyMsg('m'), 0.4));
        self::assertSame('home', $tracker->feed(new KeyMsg('e'), 0.5));
    }

    #[Test]
    public function sharedPrefixDifferentEndings(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
            'go_down' => [['g', 'd']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.0));
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.1));

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.2));
        self::assertSame('go_down', $tracker->feed(new KeyMsg('d'), 0.3));
    }

    #[Test]
    public function firstRegisteredWinsOnExactSequenceCollision(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'first_action' => [['g', 'g']],
            'second_action' => [['g', 'g']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('g'), 0.0));
        self::assertSame('first_action', $tracker->feed(new KeyMsg('g'), 0.1));
    }

    #[Test]
    public function chordWithModifierSteps(): void
    {
        $tracker = new ChordTracker(new ChordBindings([
            'vs_save' => [['ctrl+k', 'ctrl+s']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('k', ctrl: true), 0.0));
        self::assertSame('vs_save', $tracker->feed(new KeyMsg('s', ctrl: true), 0.1));
    }

    #[Test]
    public function defaultTimeoutJustUnderThreshold(): void
    {
        // Default timeout (no arg) is 500ms. Feeding at 0.499s is within.
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));

        $tracker->feed(new KeyMsg('g'), 0.0);
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.499));
    }

    #[Test]
    public function defaultTimeoutJustOverThreshold(): void
    {
        // 0.501s exceeds 500ms default — first g abandoned.
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));

        $tracker->feed(new KeyMsg('g'), 0.0);
        self::assertNull($tracker->feed(new KeyMsg('g'), 0.501));
    }

    #[Test]
    public function defaultTimeoutExactBoundaryCompletes(): void
    {
        // Default timeout is 500. Feed at exactly 0.5s: (0.5 - 0.0) * 1000 = 500.
        // 500 > 500 is false — NOT stale — chord completes.
        // Kills DecrementInteger (500 → 499) on the default param.
        $tracker = new ChordTracker(new ChordBindings([
            'go_to_top' => [['g', 'g']],
        ]));

        $tracker->feed(new KeyMsg('g'), 0.0);
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.5));
    }

    #[Test]
    public function timeoutBoundaryExactThresholdCompletes(): void
    {
        // Idle-gap timeout uses strict >, so feeding at EXACTLY timeoutMs
        // still completes (not stale). Kills GreaterThan → GreaterThanOrEqual
        // mutation.
        $tracker = new ChordTracker(
            new ChordBindings(['go_to_top' => [['g', 'g']]]),
            timeoutMs: 500,
        );

        $tracker->feed(new KeyMsg('g'), 0.0);
        self::assertSame('go_to_top', $tracker->feed(new KeyMsg('g'), 0.5));
    }

    #[Test]
    public function nonPrefixShorterKeyPassesThrough(): void
    {
        // Feed a standalone key when only a multi-step chord is bound.
        // Candidate has count 1, seq has count 2, candidate is NOT a prefix
        // of seq (first step differs). Must be passthrough.
        // Kills the `&& → ||` mutation at classify() prefix check.
        $tracker = new ChordTracker(new ChordBindings([
            'multi' => [['a', 'b']],
        ]));

        $msg = new KeyMsg('z');
        self::assertSame($msg, $tracker->feed($msg, 0.0));
    }

    #[Test]
    public function isPrefixOfRejectsNonMatchingFirstStep(): void
    {
        // Kills FalseValue / ReturnRemoval / LogicalOr mutations in
        // isPrefixOf: a candidate whose first step differs from seq's first
        // step must NOT be treated as a prefix.
        $tracker = new ChordTracker(new ChordBindings([
            'chord' => [['a', 'b', 'c']],
        ]));

        // Feed 'x' — not a prefix of ['a','b','c'] — must pass through.
        $msg = new KeyMsg('x');
        self::assertSame($msg, $tracker->feed($msg, 0.0));

        // Feed 'a' — IS a prefix — consumed.
        self::assertNull($tracker->feed(new KeyMsg('a'), 0.1));

        // Feed 'x' now — not prefix extension of ['a'] — passthrough + reset.
        $msg2 = new KeyMsg('x');
        self::assertSame($msg2, $tracker->feed($msg2, 0.2));
        self::assertSame([], $tracker->buffer());
    }

    #[Test]
    public function isPrefixOfRequiresEveryStepToMatch(): void
    {
        // Multi-step prefix check: candidate ['a', 'x'] must NOT be prefix
        // of seq ['a', 'b', 'c']. Kills Foreach_ → [] empty-iteration
        // mutation (which would make every candidate look like a prefix).
        $tracker = new ChordTracker(new ChordBindings([
            'chord' => [['a', 'b', 'c']],
        ]));

        self::assertNull($tracker->feed(new KeyMsg('a'), 0.0));  // partial, OK
        $msg = new KeyMsg('x');
        self::assertSame($msg, $tracker->feed($msg, 0.1));       // not prefix — passthrough
    }
}
