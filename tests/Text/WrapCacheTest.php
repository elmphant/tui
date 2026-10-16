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

        // Two of those exceed the budget, so the older one has gone — and what
        // comes back is still correct, just recomputed.
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

    protected function setUp(): void
    {
        WrapCache::clear();
    }

    protected function tearDown(): void
    {
        WrapCache::clear();
    }
}
