<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\TerminalAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TerminalAssertionsTest extends TestCase
{
    use TerminalAssertions;

    #[Test]
    public function textPresentOnTheScreenPasses(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HHello World", 80, 24);
        $this->assertScreenContains('Hello', $screen);
    }

    #[Test]
    public function textAtAPositionMatchesExactly(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HHello", 80, 24);
        $this->assertTextAt(0, 0, 'Hello', $screen);
    }

    #[Test]
    public function positionsCountTerminalColumnsForUnicode(): void
    {
        $frame = new Frame(8, 1);
        $frame->putString(0, 0, '世é');

        $this->assertTextAt(0, 0, '世é', ScreenBuffer::fromFrame($frame));
    }

    #[Test]
    public function aCursorWhereItIsExpectedPasses(): void
    {
        $screen = new ScreenBuffer("\x1b[3;5H", 80, 24);
        $this->assertCursorAt(2, 4, $screen);
    }

    #[Test]
    public function cursorRowMismatchesFailTheAssertion(): void
    {
        $screen = new ScreenBuffer("\x1b[3;5H", 80, 24);

        $this->expectException(AssertionFailedError::class);
        $this->assertCursorAt(1, 4, $screen);
    }

    #[Test]
    public function cursorColumnMismatchesFailTheAssertion(): void
    {
        $screen = new ScreenBuffer("\x1b[3;5H", 80, 24);

        $this->expectException(AssertionFailedError::class);
        $this->assertCursorAt(2, 3, $screen);
    }

    #[Test]
    public function aHiddenCursorPasses(): void
    {
        $screen = new ScreenBuffer("\x1b[?25l", 80, 24);
        $this->assertCursorHidden($screen);
    }

    #[Test]
    public function assertionHelpersArePublicConsumerMethods(): void
    {
        foreach (['assertScreenContains', 'assertTextAt', 'assertCursorAt', 'assertCursorHidden'] as $method) {
            self::assertTrue((new ReflectionMethod(self::class, $method))->isPublic(), "{$method} must remain public");
        }
    }
}
