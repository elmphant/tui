<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A frame has to show the keyboard where the commands put it, not where the tree
 * would have settled it.
 */
final class HarnessDrawsFocusItWasGivenTest extends TestCase
{
    #[Test]
    public function noComponentDrawsFocusedWhileTheKeyboardIsDown(): void
    {
        $frame = ModelHarness::for(new TwoFieldsKeyboardDownApp(), 40, 2)
            ->initialize()
            ->renderFrame();

        self::assertSame(
            self::row($frame, 1, 40),
            self::row($frame, 0, 40),
            'with nobody holding the keyboard the two rows have to look alike',
        );
    }

    private static function row(Frame $frame, int $y, int $width): string
    {
        $offset = $y * $width;

        return implode('|', array_slice($frame->foregrounds, $offset, $width))
            . '#' . implode('|', array_slice($frame->backgrounds, $offset, $width))
            . '#' . implode('|', array_slice($frame->attributes, $offset, $width));
    }
}
