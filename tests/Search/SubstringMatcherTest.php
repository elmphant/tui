<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Search;

use Elmphant\Tui\Search\SubstringMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubstringMatcherTest extends TestCase
{
    private const HAYSTACK = [
        'cache:warmup Warms every cache',
        'cache:flush Empties every cache',
        'user:create Adds a user',
    ];

    #[Test]
    public function anEmptyQueryKeepsEveryEntry(): void
    {
        self::assertSame([0, 1, 2], $this->matching(''));
        self::assertSame([0, 1, 2], $this->matching('   '));
    }

    #[Test]
    public function everyWordMustOccur(): void
    {
        self::assertSame([0], $this->matching('cache warms'));
        self::assertSame([], $this->matching('cache nonsense'));
    }

    #[Test]
    public function wordsMayOccurInAnyOrder(): void
    {
        self::assertSame([0], $this->matching('warms cache'));
    }

    #[Test]
    public function matchingIgnoresCase(): void
    {
        self::assertSame([0, 1], $this->matching('CACHE'));
    }

    #[Test]
    public function aSingleCharacterQueryStillNarrows(): void
    {
        self::assertSame([0], $this->matching('w'));
    }

    #[Test]
    public function resultsKeepTheOrderTheyWereHandedIn(): void
    {
        self::assertSame([0, 1], $this->matching('every'));
    }

    #[Test]
    public function anEmptyHaystackMatchesNothing(): void
    {
        self::assertSame([], (new SubstringMatcher())->matching('cache', []));
        self::assertSame([], (new SubstringMatcher())->matching('', []));
    }

    #[Test]
    public function multiByteQueriesMatchTheirOwnCase(): void
    {
        $matcher = new SubstringMatcher();

        self::assertSame([0], $matcher->matching('GRÜSSE', ['grüsse dich']));
    }

    /**
     * @return list<int>
     */
    private function matching(string $query): array
    {
        return (new SubstringMatcher())->matching($query, self::HAYSTACK);
    }
}
