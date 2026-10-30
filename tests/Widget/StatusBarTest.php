<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\StatusBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatusBarTest extends TestCase
{
    private RenderContext $context;

    #[Test]
    public function constraintIsExactOne(): void
    {
        $bar = StatusBar::new('hello');
        self::assertEquals(Constraint::exact(1), $bar->sizing()->rows);
    }

    #[Test]
    public function hintsThatDoNotAllFitEndInAnEllipsisWhereThereIsRoomForOne(): void
    {
        $lines = [
            27 => ' a  one   b  two   c  three' . str_repeat('.', 13),
            19 => ' a  one   b  two  …' . str_repeat('.', 21),
            18 => ' a  one  …' . str_repeat(' ', 8) . str_repeat('.', 22),
            10 => ' a  one  …' . str_repeat('.', 30),
            9 => '…' . str_repeat(' ', 8) . str_repeat('.', 31),
        ];

        foreach ($lines as $width => $line) {
            self::assertSame($line, $this->hintsDrawnIn($width), "a bar $width wide");
        }
    }

    #[Test]
    public function aBarOfHintsAsksForTheirWidthWithoutTheLastGap(): void
    {
        self::assertSame(27, StatusBar::forHints(['a' => 'one', 'b' => 'two', 'c' => 'three'])->sizing()->columns->basis);
    }

    #[Test]
    public function plainTextRendersContent(): void
    {
        $bar = StatusBar::new('hello world');
        $frame = new Frame(40, 1);
        $bar->render($frame, Rect::fromSize(40, 1), $this->context);

        $text = $this->extractText($frame, 0, 11);
        self::assertSame('hello world', $text);
    }

    #[Test]
    public function hintsRendersKeyDescriptionPairs(): void
    {
        $bar = StatusBar::forHints(['Tab' => 'cycle', 'Esc' => 'quit']);
        $frame = new Frame(50, 1);
        $bar->render($frame, Rect::fromSize(50, 1), $this->context);

        $text = $this->extractText($frame, 0, 30);
        self::assertStringContainsString('Tab', $text);
        self::assertStringContainsString('cycle', $text);
        self::assertStringContainsString('Esc', $text);
        self::assertStringContainsString('quit', $text);
    }

    #[Test]
    public function hintsKeyHasDifferentStyleThanDescription(): void
    {
        $bar = StatusBar::forHints(['Tab' => 'cycle']);
        $frame = new Frame(40, 1);
        $bar->render($frame, Rect::fromSize(40, 1), $this->context);

        $keyIndex = 1;
        $descriptionIndex = 6;
        $keyForeground = $frame->foregrounds[$keyIndex];
        $descriptionForeground = $frame->foregrounds[$descriptionIndex];

        self::assertNotSame($keyForeground, $descriptionForeground);
    }

    #[Test]
    public function hintsConstraintIsExactOne(): void
    {
        $bar = StatusBar::forHints(['Tab' => 'cycle']);
        self::assertEquals(Constraint::exact(1), $bar->sizing()->rows);
    }

    // --- L46: Coalesce x2 — kills mutants that swap ?? operands ---

    #[Test]
    public function barUsesTheThemeFooterStyle(): void
    {
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $bar = StatusBar::new('test');
        $frame = new Frame(20, 1);
        $bar->render($frame, Rect::fromSize(20, 1), $context);

        self::assertSame($theme->footerStyle->backgroundValue(), $frame->backgrounds[0]);
        self::assertSame($theme->footerStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function barUsesACustomThemeFooterStyle(): void
    {
        $footerStyle = new Style(foreground: Color::hex('101010'), background: Color::hex('FFCC00'));
        $context = new RenderContext(Theme::dark()->withFooterStyle($footerStyle));
        $bar = StatusBar::new('test');
        $frame = new Frame(20, 1);
        $bar->render($frame, Rect::fromSize(20, 1), $context);

        self::assertSame(Color::hex('FFCC00')->value, $frame->backgrounds[0]);
        self::assertSame(Color::hex('101010')->value, $frame->foregrounds[0]);
    }

    #[Test]
    public function barTextIsNotDimmedAgainstTheBarBackground(): void
    {
        // Regression: dim text on the selection background was unreadable.
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $bar = StatusBar::new('test');
        $frame = new Frame(20, 1);
        $bar->render($frame, Rect::fromSize(20, 1), $context);

        self::assertNotSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
    }

    // --- L48: fillRect height=1 — DecrementInteger (0), IncrementInteger (2) ---

    #[Test]
    public function fillRectCoversExactlyOneRow(): void
    {
        $bar = StatusBar::new('x');
        $frame = new Frame(10, 3);
        $bar->render($frame, new Rect(0, 1, 10, 1), $this->context);

        $barBackground = $frame->backgrounds[1 * 10];
        self::assertNotSame(0, $barBackground);

        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->backgrounds[2 * 10]);
    }

    // --- L48: MethodCallRemoval — removing fillRect entirely ---

    #[Test]
    public function fillRectAppliesBackgroundToEntireBarWidth(): void
    {
        $bar = StatusBar::new('');
        $frame = new Frame(20, 1);
        $bar->render($frame, Rect::fromSize(20, 1), $this->context);

        $theme = $this->context->theme;
        $expectedBackground = $theme->dim->background(
            $theme->selectedStyle->background ?? $theme->base->foreground ?? Color::ansi(0),
        )->backgroundValue();

        for ($x = 0; $x < 20; ++$x) {
            self::assertSame($expectedBackground, $frame->backgrounds[$x], "Background mismatch at column $x");
        }
    }

    // --- L48: area->x and area->y used correctly ---

    #[Test]
    public function renderRespectsAreaOffset(): void
    {
        $bar = StatusBar::new('AB');
        $frame = new Frame(20, 5);
        $area = new Rect(3, 2, 10, 1);
        $bar->render($frame, $area, $this->context);

        self::assertSame('A', $frame->characters[2 * 20 + 3]);
        self::assertSame('B', $frame->characters[2 * 20 + 4]);

        self::assertSame(' ', $frame->characters[2 * 20 + 2]);
    }

    // --- L52: ReturnRemoval — without early return, content text would also render after hints ---

    #[Test]
    public function hintsDoNotRenderContentText(): void
    {
        $bar = StatusBar::forHints(['K' => 'v']);
        $frame = new Frame(40, 1);
        $bar->render($frame, Rect::fromSize(40, 1), $this->context);

        $barFromNew = StatusBar::new('');
        $bufferNew = new Frame(40, 1);
        $barFromNew->render($bufferNew, Rect::fromSize(40, 1), $this->context);

        $hintsText = $this->extractText($frame, 0, 40);
        self::assertStringContainsString('K', $hintsText);

        $newText = $this->extractText($bufferNew, 0, 40);
        self::assertStringNotContainsString('K', $newText);
    }

    #[Test]
    public function earlyReturnPreventsContentRenderingAfterHints(): void
    {
        $bar = StatusBar::forHints(['X' => 'Y']);
        $frame = new Frame(40, 1);
        $bar->render($frame, Rect::fromSize(40, 1), $this->context);

        $keyForeground = $frame->foregrounds[1];
        $afterHintsIndex = 10;
        $afterHintsForeground = $frame->foregrounds[$afterHintsIndex];

        self::assertNotSame($keyForeground, $afterHintsForeground);
    }

    // --- L60: Coalesce — $barStyle->background ?? Color::ansi(0) ---

    #[Test]
    public function hintKeyStyleUsesBarBackgroundWhenPresent(): void
    {
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $bar = StatusBar::forHints(['K' => 'desc']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $context);

        $barBackground = $theme->selectedStyle->background;
        self::assertNotNull($barBackground);
        $expectedKeyBackground = $theme->accent->background($barBackground)->bold()->backgroundValue();
        self::assertSame($expectedKeyBackground, $frame->backgrounds[1]);
    }

    #[Test]
    public function hintKeyStyleFallsToAnsi0WhenBarStyleHasNoBackground(): void
    {
        $theme = Theme::dark()->withFooterStyle(new Style(foreground: Color::hex('AAAAAA')));
        $context = new RenderContext($theme);
        $bar = StatusBar::forHints(['K' => 'desc']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $context);

        $expectedKeyBackground = $theme->accent->background(Color::ansi(0))->bold()->backgroundValue();
        self::assertSame($expectedKeyBackground, $frame->backgrounds[1]);
    }

    // --- L60: DecrementInteger / IncrementInteger ---

    #[Test]
    public function hintKeyAnsi0FallbackProducesCorrectColorValue(): void
    {
        $theme = Theme::dark()->withFooterStyle(new Style(foreground: Color::hex('CCCCCC')));
        $context = new RenderContext($theme);
        $bar = StatusBar::forHints(['K' => 'desc']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $context);

        $ansi0Bg = $theme->accent->background(Color::ansi(0))->bold()->backgroundValue();
        $ansi1Bg = $theme->accent->background(Color::ansi(1))->bold()->backgroundValue();
        self::assertSame($ansi0Bg, $frame->backgrounds[1]);
        self::assertNotSame($ansi1Bg, $frame->backgrounds[1]);
    }

    // --- L64: Concat / ConcatOperandRemoval on ' ' . $key . ' ' ---

    #[Test]
    public function hintKeyIsPaddedWithSpaces(): void
    {
        $bar = StatusBar::forHints(['K' => 'desc']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('K', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function hintKeySpacePrefixIsRendered(): void
    {
        $bar = StatusBar::forHints(['AB' => 'x']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('A', $frame->characters[1]);
        self::assertSame('B', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
    }

    #[Test]
    public function hintKeySpaceSuffixIsRendered(): void
    {
        $bar = StatusBar::forHints(['A' => 'x']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('A', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    // --- L65: Concat / ConcatOperandRemoval on ' ' . $description . '  ' ---

    #[Test]
    public function hintDescriptionIsPaddedCorrectly(): void
    {
        $bar = StatusBar::forHints(['K' => 'go']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        // ' K ' = 3 chars at positions 0,1,2
        // ' go  ' = 5 chars at positions 3,4,5,6,7
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame('g', $frame->characters[4]);
        self::assertSame('o', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[6]);
        self::assertSame(' ', $frame->characters[7]);
    }

    #[Test]
    public function hintDescriptionSpacePrefixIsRendered(): void
    {
        $bar = StatusBar::forHints(['K' => 'x']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        // ' K ' at 0,1,2 then ' x  ' at 3,4,5,6
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame('x', $frame->characters[4]);
    }

    #[Test]
    public function hintDescriptionDoubleSpaceSuffixIsRendered(): void
    {
        $bar = StatusBar::forHints(['K' => 'x']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        // ' K ' at 0,1,2 then ' x  ' at 3,4,5,6
        self::assertSame(' ', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[6]);
    }

    // --- L64-65: Verify hint key and description have their respective styles applied to padding ---

    #[Test]
    public function hintKeyPaddingHasKeyStyle(): void
    {
        $bar = StatusBar::forHints(['K' => 'desc']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        $keyCharForeground = $frame->foregrounds[1];
        self::assertSame($keyCharForeground, $frame->foregrounds[0]);
        self::assertSame($keyCharForeground, $frame->foregrounds[2]);
    }

    #[Test]
    public function hintDescriptionPaddingHasBarStyle(): void
    {
        $bar = StatusBar::forHints(['K' => 'go']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        // description chars at 4,5; padding at 3,6,7
        $descCharForeground = $frame->foregrounds[4];
        self::assertSame($descCharForeground, $frame->foregrounds[3]);
        self::assertSame($descCharForeground, $frame->foregrounds[6]);
        self::assertSame($descCharForeground, $frame->foregrounds[7]);
    }

    // --- Multiple hints advance x correctly (validates L64-65 x += behavior) ---

    #[Test]
    public function multipleHintsRenderSequentially(): void
    {
        $bar = StatusBar::forHints(['A' => 'x', 'B' => 'y']);
        $frame = new Frame(30, 1);
        $bar->render($frame, Rect::fromSize(30, 1), $this->context);

        // ' A ' = 0,1,2  ' x  ' = 3,4,5,6  ' B ' = 7,8,9  ' y  ' = 10,11,12,13
        self::assertSame('A', $frame->characters[1]);
        self::assertSame('x', $frame->characters[4]);
        self::assertSame('B', $frame->characters[8]);
        self::assertSame('y', $frame->characters[11]);
    }

    #[Test]
    public function renderTruncatesContentToAreaWidth(): void
    {
        $bar = StatusBar::new('Hello World Long Text');
        $frame = new Frame(15, 1);
        $bar->render($frame, new Rect(0, 0, 10, 1), $this->context);

        self::assertSame(' ', $frame->characters[10]);
    }

    #[Test]
    public function hintsDescriptionNotRenderedWhenKeyFillsEntireWidth(): void
    {
        $bar = StatusBar::forHints(['K' => 'description']);
        $frame = new Frame(10, 1);
        $bar->render($frame, new Rect(0, 0, 3, 1), $this->context);

        self::assertSame(' ', $frame->characters[3]);
    }

    #[Test]
    public function aHintThatDoesNotFitIsLeftOutAndAnEllipsisSaysSo(): void
    {
        $bar = StatusBar::forHints(['Tab' => 'cycle', 'Esc' => 'quit']);
        $frame = new Frame(16, 1);
        $bar->render($frame, Rect::fromSize(16, 1), $this->context);

        self::assertSame(' Tab  cycle  …', rtrim($this->extractText($frame, 0, 16)));
    }

    #[Test]
    public function theLastHintNeedsNoRoomForTheGapAfterIt(): void
    {
        $bar = StatusBar::forHints(['Tab' => 'cycle', 'Esc' => 'quit']);
        $frame = new Frame(23, 1);
        $bar->render($frame, Rect::fromSize(23, 1), $this->context);

        self::assertSame(' Tab  cycle   Esc  quit', $this->extractText($frame, 0, 23));
    }

    #[Test]
    public function hintsDoNotOverflowAreaBoundary(): void
    {
        $bar = StatusBar::forHints(['K' => 'description text']);
        $frame = new Frame(20, 1);
        $bar->render($frame, new Rect(5, 0, 6, 1), $this->context);

        self::assertSame(' ', $frame->characters[11]);
    }

    #[Test]
    public function hintsKeyTruncatedInNarrowAreaWithOffset(): void
    {
        $bar = StatusBar::forHints(['LongKey' => 'desc']);
        $frame = new Frame(20, 1);
        $bar->render($frame, new Rect(5, 0, 4, 1), $this->context);

        self::assertSame(' ', $frame->characters[9]);
    }

    #[Test]
    public function hintsDescriptionTruncatedInNarrowAreaWithOffset(): void
    {
        $bar = StatusBar::forHints(['K' => 'LongDescription']);
        $frame = new Frame(20, 1);
        $bar->render($frame, new Rect(5, 0, 6, 1), $this->context);

        self::assertSame(' ', $frame->characters[11]);
    }

    #[Test]
    public function hintsSecondKeyNotRenderedWhenFirstFillsArea(): void
    {
        $bar = StatusBar::forHints(['A' => 'x', 'B' => 'y']);
        $frame = new Frame(10, 1);
        $bar->render($frame, new Rect(0, 0, 6, 1), $this->context);

        self::assertSame(0, $frame->foregrounds[6]);
    }

    #[Test]
    public function hintsDescriptionNotStyledWhenKeyExactlyFillsRemaining(): void
    {
        $bar = StatusBar::forHints(['AB' => 'desc']);
        $frame = new Frame(10, 1);
        $bar->render($frame, new Rect(0, 0, 4, 1), $this->context);

        self::assertSame(0, $frame->foregrounds[4]);
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private function extractText(Frame $frame, int $startX, int $length): string
    {
        $result = '';
        for ($x = $startX; $x < $startX + $length; ++$x) {
            $result .= $frame->character($x);
        }

        return $result;
    }

    private function hintsDrawnIn(int $width): string
    {
        $frame = new Frame(40, 1);
        $frame->putString(0, 0, str_repeat('.', 40));
        StatusBar::forHints(['a' => 'one', 'b' => 'two', 'c' => 'three'])->render($frame, new Rect(0, 0, $width, 1), $this->context);

        return $this->extractText($frame, 0, 40);
    }
}
