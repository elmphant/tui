<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\FilterableTable;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The query is one thing, kept in two places: a string of its own and the field the
 * reader typed it into. Setting only the string left the field showing what was
 * there before, so the bar read one filter while the rows were narrowed by another
 * — and a host clearing the filter left the old text on the screen.
 */
final class AFilterableTableShowsTheQueryItFiltersByTest extends TestCase
{
    #[Test]
    public function clearingTheQueryClearsWhatTheFieldShows(): void
    {
        $typedIn = FilterableTable::new(['Name'], [['alpha'], ['beta']])
            ->withField(TextInput::new()->withValue('alpha'));
        self::assertSame('alpha', $typedIn->field()->value(), 'precondition: the reader typed a filter');

        $cleared = $typedIn->withQuery('');

        self::assertSame('', $cleared->field()->value(), 'the field reads what the rows are narrowed by');
    }
}
