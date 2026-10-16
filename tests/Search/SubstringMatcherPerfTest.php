<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Search;

use Elmphant\Tui\Search\SubstringMatcher;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubstringMatcherPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function anAsciiQueryCostsAboutWhatAByteSearchCosts(): void
    {
        self::skipUnderXdebug();

        $haystack = [];
        for ($index = 0; $index < 50_000; ++$index) {
            $haystack[] = sprintf('entry %05d café 漢字 workload', $index);
        }
        $matcher = new SubstringMatcher();

        $matched = self::medianMicroseconds(static fn (): array => $matcher->matching('Workload 123', $haystack), 10);
        $bytes = self::medianMicroseconds(static fn (): array => array_keys(array_filter(
            $haystack,
            static fn (string $entry): bool => stripos($entry, 'workload') !== false && stripos($entry, '123') !== false,
        )), 10);

        self::assertLessThan(4.0, $matched / $bytes, sprintf('%.0fµs against %.0fµs', $matched, $bytes));
    }
}
