<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Testing\ModelHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChordTest extends TestCase
{
    #[Test]
    public function aTwoKeyChordMovesAListToItsFirstRow(): void
    {
        $harness = ModelHarness::for(new ChordListApp(), 30, 8)->initialize()->press('down', 'down', 'g', 'g');

        self::assertSame(0, $harness->model()->list->cursor());
    }
}
