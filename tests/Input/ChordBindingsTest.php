<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\ChordBindings;
use Elmphant\Tui\Input\KeyPattern;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChordBindingsTest extends TestCase
{
    #[Test]
    public function emptyHasNoActions(): void
    {
        self::assertSame([], ChordBindings::empty()->actions());
    }

    #[Test]
    public function emptyIsCachedSingleton(): void
    {
        self::assertSame(ChordBindings::empty(), ChordBindings::empty());
    }

    #[Test]
    public function constructParsesStringSequences(): void
    {
        $bindings = new ChordBindings(['go_to_top' => [['g', 'g']]]);

        $sequences = $bindings->sequencesFor('go_to_top');

        self::assertCount(1, $sequences);
        self::assertCount(2, $sequences[0]);
        self::assertEquals(KeyPattern::fromString('g'), $sequences[0][0]);
        self::assertEquals(KeyPattern::fromString('g'), $sequences[0][1]);
    }

    #[Test]
    public function constructAcceptsPreBuiltKeyPatterns(): void
    {
        $preBuilt = KeyPattern::fromString('ctrl+c');
        $bindings = new ChordBindings(['x' => [['g', $preBuilt]]]);

        $sequences = $bindings->sequencesFor('x');
        self::assertSame($preBuilt, $sequences[0][1]);
    }

    #[Test]
    public function constructContinuesAfterPreBuiltKeyPattern(): void
    {
        // Kills Continue_ → break mutation: ensure BOTH steps are parsed
        // when a KeyPattern-instance step is followed by a string step.
        $preBuilt = KeyPattern::fromString('g');
        $bindings = new ChordBindings(['x' => [[$preBuilt, 'h']]]);

        $sequences = $bindings->sequencesFor('x');
        self::assertCount(2, $sequences[0]);
        self::assertSame($preBuilt, $sequences[0][0]);
        self::assertEquals(KeyPattern::fromString('h'), $sequences[0][1]);
    }

    #[Test]
    public function constructMultipleSequencesForOneAction(): void
    {
        $bindings = new ChordBindings([
            'home' => [['g', 'g'], ['home']],
        ]);

        self::assertCount(2, $bindings->sequencesFor('home'));
    }

    #[Test]
    public function actionsListsAllActions(): void
    {
        $bindings = new ChordBindings([
            'a' => [['g', 'g']],
            'b' => [['x', 'y']],
        ]);

        self::assertSame(['a', 'b'], $bindings->actions());
    }

    #[Test]
    public function sequencesForUnknownActionReturnsEmpty(): void
    {
        $bindings = new ChordBindings(['a' => [['g', 'g']]]);

        self::assertSame([], $bindings->sequencesFor('unknown'));
    }

    #[Test]
    public function constructRejectsEmptySequenceList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no sequences/');

        new ChordBindings(['foo' => []]);
    }

    #[Test]
    public function constructRejectsEmptySequence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Empty sequence/');

        new ChordBindings(['foo' => [[]]]);
    }

    #[Test]
    public function constructWrapsParserErrorWithActionName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/chord step for action 'foo'/");

        new ChordBindings(['foo' => [['g', 'hyper+bogus']]]);
    }

    #[Test]
    public function constructWrappedErrorIncludesOriginalMessage(): void
    {
        try {
            new ChordBindings(['foo' => [['hyper+a']]]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            // Action-name context comes FIRST, then the original error.
            // Kills Concat order-swap mutation.
            self::assertStringStartsWith("Invalid chord step for action 'foo':", $exception->getMessage());
            self::assertStringContainsString('Unknown modifier', $exception->getMessage());
            self::assertStringContainsString('hyper', $exception->getMessage());
        }
    }

    #[Test]
    public function allSequencesFlattensAcrossActions(): void
    {
        $bindings = new ChordBindings([
            'a' => [['g', 'g']],
            'b' => [['x'], ['y', 'z']],
        ]);

        $all = $bindings->allSequences();

        self::assertCount(3, $all);
        self::assertSame('a', $all[0]['action']);
        self::assertCount(2, $all[0]['pattern']);
        self::assertSame('b', $all[1]['action']);
        self::assertSame('b', $all[2]['action']);
    }
}
