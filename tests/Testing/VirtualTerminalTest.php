<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Rendering\Ansi;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VirtualTerminalTest extends TestCase
{
    #[Test]
    public function sizeReturnsConfiguredDimensions(): void
    {
        $terminal = new VirtualTerminal(120, 40);
        $this->assertSame([120, 40], $terminal->size());
    }

    #[Test]
    public function writeCapturesOutput(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->write('hello');
        $terminal->write(' world');
        $this->assertSame('hello world', $terminal->output());
    }

    #[Test]
    public function clearOutputResetsBuffer(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->write('hello');
        $terminal->clearOutput();
        $this->assertSame('', $terminal->output());
    }

    #[Test]
    public function simulateInputIsReadBack(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('abc');
        $this->assertSame('abc', $terminal->readInput());
    }

    #[Test]
    public function readInputDrainsBuffer(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('abc');
        $terminal->readInput();
        $this->assertSame('', $terminal->readInput());
    }

    #[Test]
    public function simulateInputAccumulatesMultipleCalls(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('ab');
        $terminal->simulateInput('cd');
        $this->assertSame('abcd', $terminal->readInput());
    }

    #[Test]
    public function waitForInputReturnsTrueWhenBuffered(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');
        $this->assertTrue($terminal->waitForInput(0));
    }

    #[Test]
    public function queryKittyProtocolWritesQuerySequence(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->queryKittyProtocol();
        $this->assertSame(Ansi::QUERY_KITTY_KEYBOARD, $terminal->output());
    }

    #[Test]
    public function enableAndDisableKittyProtocol(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $this->assertFalse($terminal->isKittyEnabled());

        $terminal->enableKittyProtocol();
        $this->assertTrue($terminal->isKittyEnabled());
        $this->assertStringContainsString(Ansi::ENABLE_KITTY_KEYBOARD, $terminal->output());

        $terminal->clearOutput();
        $terminal->disableKittyProtocol();
        $this->assertFalse($terminal->isKittyEnabled());
        $this->assertStringContainsString(Ansi::DISABLE_KITTY_KEYBOARD, $terminal->output());
    }

    #[Test]
    public function bellWritesAsciiBellByte(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->bell();
        $this->assertSame("\x07", $terminal->output());
    }

    #[Test]
    public function setTitleWritesOscSequence(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->setTitle('Hello');
        $this->assertSame("\x1b]0;Hello\x07", $terminal->output());
    }
}
