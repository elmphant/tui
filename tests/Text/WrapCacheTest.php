<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Text;

use Elmphant\Tui\Text\WrapCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WrapCacheTest extends TestCase
{
    #[Test]
    public function wrapsOncePerKey(): void
    {
        $calls = 0;
        $wrap = static function () use (&$calls): array {
            ++$calls;

            return ['one', 'two'];
        };

        self::assertSame(['one', 'two'], WrapCache::remember('key', $wrap));
        self::assertSame(['one', 'two'], WrapCache::remember('key', $wrap));
        self::assertSame(1, $calls);
    }

    #[Test]
    public function countsEveryWrapItHadToRun(): void
    {
        self::assertSame(0, WrapCache::wraps(), 'Clearing the cache resets the count');

        WrapCache::remember('one', static fn (): array => ['a']);
        WrapCache::remember('one', static fn (): array => ['a']);
        WrapCache::remember('two', static fn (): array => ['b']);

        self::assertSame(2, WrapCache::wraps());
    }

    #[Test]
    public function keepsKeysApart(): void
    {
        WrapCache::remember('narrow', static fn (): array => ['a', 'b']);

        self::assertSame(['c'], WrapCache::remember('wide', static fn (): array => ['c']));
        self::assertSame(['a', 'b'], WrapCache::remember('narrow', static fn (): array => ['wrong']));
    }

    #[Test]
    public function evictsToStayWithinItsRowBudget(): void
    {
        $huge = array_fill(0, 40_000, 'row');

        WrapCache::remember('first', static fn (): array => $huge);
        WrapCache::remember('second', static fn (): array => $huge);

        $recomputed = 0;
        WrapCache::remember('first', static function () use ($huge, &$recomputed): array {
            ++$recomputed;

            return $huge;
        });

        self::assertSame(1, $recomputed);
        self::assertCount(40_000, WrapCache::remember('first', static fn (): array => ['wrong']));
    }

    #[Test]
    public function neverEvictsTheOnlyEntryItHas(): void
    {
        $enormous = array_fill(0, 60_000, 'row');

        WrapCache::remember('only', static fn (): array => $enormous);

        $recomputed = 0;
        WrapCache::remember('only', static function () use ($enormous, &$recomputed): array {
            ++$recomputed;

            return $enormous;
        });

        self::assertSame(0, $recomputed, 'Dropping the entry in use would re-wrap it every single frame');
    }

    #[Test]
    public function aBudgetFilledExactlyKeepsEverything(): void
    {
        WrapCache::remember('first', static fn (): array => array_fill(0, 20_000, 'row'));
        WrapCache::remember('second', static fn (): array => array_fill(0, 30_000, 'row'));

        self::assertFalse(self::recomputes('first'));
    }

    #[Test]
    public function oneRowOverTheBudgetEvictsTheOldest(): void
    {
        WrapCache::remember('first', static fn (): array => array_fill(0, 20_000, 'row'));
        WrapCache::remember('second', static fn (): array => array_fill(0, 30_001, 'row'));

        self::assertTrue(self::recomputes('first'));
    }

    #[Test]
    public function anEvictionGivesBackExactlyTheRowsItFreed(): void
    {
        WrapCache::remember('first', static fn (): array => array_fill(0, 20_000, 'row'));
        WrapCache::remember('second', static fn (): array => array_fill(0, 40_000, 'row'));
        WrapCache::remember('third', static fn (): array => array_fill(0, 9_000, 'row'));

        self::assertFalse(self::recomputes('second'), '40 000 + 9 000 rows still fit');

        WrapCache::remember('fourth', static fn (): array => array_fill(0, 2_000, 'row'));

        self::assertTrue(self::recomputes('second'), '40 000 + 9 000 + 2 000 rows do not');
    }

    protected function setUp(): void
    {
        WrapCache::clear();
    }

    protected function tearDown(): void
    {
        WrapCache::clear();
    }

    private static function recomputes(string $key): bool
    {
        $recomputed = false;
        WrapCache::remember($key, static function () use (&$recomputed): array {
            $recomputed = true;

            return ['row'];
        });

        return $recomputed;
    }
}
