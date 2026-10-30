<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessDrawsFocusItWasGivenTest extends TestCase
{
    #[Test]
    public function noComponentDrawsFocusedWhileTheKeyboardIsDown(): void
    {
        $frame = ModelHarness::for(new TwoFieldsKeyboardDownApp(TextInput::new(), TextInput::new()), 40, 2)
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
