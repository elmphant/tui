<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollbarPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function renderPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(80, 50);

        self::assertWithinBudget(
            30.0,
            self::medianMicroseconds(static fn (): null => Scrollbar::render($frame, 79, 0, 40, 10, 200, 40, 7, 8, 0)),
            'Scrollbar::render',
        );
    }

    #[Test]
    public function aLongerContentListDoesNotCostMoreToDraw(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(80, 50);

        $short = self::medianMicroseconds(
            static fn (): null => Scrollbar::render($frame, 79, 0, 40, 10, 200, 40, 7, 8, 0),
        );
        $long = self::medianMicroseconds(
            static fn (): null => Scrollbar::render($frame, 79, 0, 40, 10, 2_000_000, 40, 7, 8, 0),
        );
        $ratio = $long / $short;

        self::assertLessThan(
            self::MAX_RATIO,
            $ratio,
            sprintf(
                'A 10000x longer list made Scrollbar::render %.1fx slower (%.1fµs vs %.1fµs). '
                . 'The cost must track the track height, never the content.',
                $ratio,
                $long,
                $short,
            ),
        );
    }
}
