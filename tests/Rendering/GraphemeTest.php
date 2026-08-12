<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Grapheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphemeTest extends TestCase
{
    #[Test]
    public function combiningMarkStaysWithBase(): void
    {
        $this->assertSame(["e\u{0301}"], Grapheme::split("e\u{0301}"));
    }

    #[Test]
    public function emojiZwjSequenceIsSingleGrapheme(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
        $this->assertCount(1, Grapheme::split($family));
    }

    #[Test]
    public function flagIsSingleGrapheme(): void
    {
        $this->assertCount(1, Grapheme::split("\u{1F1FA}\u{1F1F8}"));
    }

    #[Test]
    public function mixedAsciiAndEmoji(): void
    {
        $this->assertSame(['H', 'i', "\u{1F44B}"], Grapheme::split("Hi\u{1F44B}"));
    }

    #[Test]
    public function firstTakesTheLeadingClusterWhole(): void
    {
        $this->assertSame("\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}", Grapheme::first("\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}tail"));
    }
}
