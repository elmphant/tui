<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\SpanRenderer;
use Elmphant\Tui\Rendering\StyleToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpanRendererTest extends TestCase
{
    #[Test]
    public function plainTextProducesSingleLineWithOneSpan(): void
    {
        $lines = SpanRenderer::fromMarkdown('Hello world');

        self::assertCount(1, $lines);
        self::assertCount(1, $lines[0]->spans);
        self::assertSame('Hello world', $lines[0]->spans[0]->content);
        self::assertNull($lines[0]->spans[0]->style, 'Plain text must produce a span with no style — not Style(attributes: 0)');
    }

    #[Test]
    public function aBrokenByteInTheMarkdownIsReplacedRatherThanThrown(): void
    {
        $lines = SpanRenderer::fromMarkdown("ok \xff");

        self::assertSame('ok ?', $lines[0]->spans[0]->content);
    }

    #[Test]
    public function boldTextProducesSpanWithBoldAttribute(): void
    {
        $lines = SpanRenderer::fromMarkdown('Hello **bold** text');

        self::assertCount(1, $lines);
        self::assertSame('Hello ', $lines[0]->spans[0]->content);
        self::assertSame('bold', $lines[0]->spans[1]->content);
        self::assertSame(Attr::BOLD, $lines[0]->spans[1]->style->attributes);
        self::assertSame(' text', $lines[0]->spans[2]->content);
    }

    #[Test]
    public function italicTextProducesSpanWithItalicAttribute(): void
    {
        $lines = SpanRenderer::fromMarkdown('Hello *italic* text');

        self::assertCount(1, $lines);
        self::assertSame('italic', $lines[0]->spans[1]->content);
        self::assertSame(Attr::ITALIC, $lines[0]->spans[1]->style->attributes);
    }

    #[Test]
    public function strikethroughProducesSpanWithStrikethroughAttribute(): void
    {
        $lines = SpanRenderer::fromMarkdown('Hello ~~struck~~ text');

        self::assertCount(1, $lines);
        self::assertSame('struck', $lines[0]->spans[1]->content);
        self::assertSame(Attr::STRIKETHROUGH, $lines[0]->spans[1]->style->attributes);
    }

    #[Test]
    public function inlineCodeProducesSpanWithReverseAttribute(): void
    {
        $lines = SpanRenderer::fromMarkdown('Hello `code` text');

        self::assertCount(1, $lines);
        self::assertCount(3, $lines[0]->spans, 'Walker must continue past inline code to render the trailing text span');
        self::assertSame('code', $lines[0]->spans[1]->content);
        self::assertSame(Attr::REVERSE, $lines[0]->spans[1]->style->attributes);
        self::assertSame(' text', $lines[0]->spans[2]->content);
    }

    #[Test]
    public function newlinesProduceSeparateLines(): void
    {
        $lines = SpanRenderer::fromMarkdown("Line one\n\nLine two");

        self::assertCount(2, $lines);
        self::assertSame('Line one', $lines[0]->spans[0]->content);
        self::assertSame('Line two', $lines[1]->spans[0]->content);
    }

    #[Test]
    public function nestedBoldAndItalic(): void
    {
        $lines = SpanRenderer::fromMarkdown('***both***');

        self::assertCount(1, $lines);
        self::assertSame('both', $lines[0]->spans[0]->content);
        self::assertSame(
            Attr::BOLD | Attr::ITALIC,
            $lines[0]->spans[0]->style->attributes,
        );
    }

    #[Test]
    public function softBreakBecomesSpace(): void
    {
        $lines = SpanRenderer::fromMarkdown("Hello\nworld");

        self::assertCount(1, $lines);
        self::assertSame('Hello', $lines[0]->spans[0]->content);
        self::assertSame(' ', $lines[0]->spans[1]->content);
        self::assertSame('world', $lines[0]->spans[2]->content);
    }

    #[Test]
    public function aHeadingIsABoldTitleLine(): void
    {
        $lines = SpanRenderer::fromMarkdown("# Heading\n\nParagraph body");

        self::assertEquals(
            [
                new Line([Span::token('Heading', StyleToken::Title)->bold()]),
                new Line([new Span('Paragraph body')]),
            ],
            $lines,
        );
    }

    #[Test]
    public function aBulletListMarksEachItemAndIndentsANestedOne(): void
    {
        $lines = SpanRenderer::fromMarkdown("- one\n- two\n  - nested");

        self::assertEquals(
            [
                new Line([new Span('• '), new Span('one')]),
                new Line([new Span('• '), new Span('two')]),
                new Line([new Span('  '), new Span('• '), new Span('nested')]),
            ],
            $lines,
        );
    }

    #[Test]
    public function anOrderedListCountsFromItsStart(): void
    {
        $lines = SpanRenderer::fromMarkdown("3. three\n4. four");

        self::assertEquals(
            [
                new Line([new Span('3. '), new Span('three')]),
                new Line([new Span('4. '), new Span('four')]),
            ],
            $lines,
        );
    }

    #[Test]
    public function aCodeBlockKeepsEachLineAndItsIndentation(): void
    {
        $lines = SpanRenderer::fromMarkdown("```php\nif (\$x) {\n\treturn;\n}\n```");

        self::assertEquals(
            [
                new Line([new Span('  '), Span::token('if ($x) {', StyleToken::Accent)]),
                new Line([new Span('  '), Span::token('        return;', StyleToken::Accent)]),
                new Line([new Span('  '), Span::token('}', StyleToken::Accent)]),
            ],
            $lines,
        );
    }

    #[Test]
    public function aQuoteMarksEveryLineOfWhatItHolds(): void
    {
        $lines = SpanRenderer::fromMarkdown("> said\n>\n> - item");

        self::assertEquals(
            [
                new Line([Span::token('│ ', StyleToken::Dim), new Span('said')]),
                new Line([Span::token('│ ', StyleToken::Dim), new Span('• '), new Span('item')]),
            ],
            $lines,
        );
    }

    #[Test]
    public function anIndentedCodeBlockIsACodeBlockToo(): void
    {
        $lines = SpanRenderer::fromMarkdown('    echo 1;');

        self::assertEquals([new Line([new Span('  '), Span::token('echo 1;', StyleToken::Accent)])], $lines);
    }

    #[Test]
    public function rawHtmlIsShownAsDimText(): void
    {
        $lines = SpanRenderer::fromMarkdown("<details>\n<summary>More</summary>\n</details>");

        self::assertEquals(
            [
                new Line([Span::token('<details>', StyleToken::Dim)]),
                new Line([Span::token('<summary>More</summary>', StyleToken::Dim)]),
                new Line([Span::token('</details>', StyleToken::Dim)]),
            ],
            $lines,
        );
    }

    #[Test]
    public function aRuleIsADimLine(): void
    {
        $lines = SpanRenderer::fromMarkdown("above\n\n---\n\nbelow");

        self::assertEquals(
            [
                new Line([new Span('above')]),
                new Line([Span::token('───', StyleToken::Dim)]),
                new Line([new Span('below')]),
            ],
            $lines,
        );
    }

    #[Test]
    public function aLinkShowsItsTextInTheSurroundingStyle(): void
    {
        self::assertEquals(
            [new Line([new Span('see '), new Span('docs'), new Span(' here')])],
            SpanRenderer::fromMarkdown('see [docs](https://example.com) here'),
        );
    }

    #[Test]
    public function hardBreakBecomesSpace(): void
    {
        $lines = SpanRenderer::fromMarkdown("Hello  \nworld");

        self::assertCount(1, $lines);
        self::assertSame('Hello', $lines[0]->spans[0]->content);
        self::assertSame(' ', $lines[0]->spans[1]->content);
        self::assertSame('world', $lines[0]->spans[2]->content);
    }

    #[Test]
    public function emptyStringProducesEmptyLine(): void
    {
        $lines = SpanRenderer::fromMarkdown('');

        self::assertCount(1, $lines);
        self::assertCount(0, $lines[0]->spans);
    }

    #[Test]
    public function emphasisInsideStrongCombinesBothAttributes(): void
    {
        $lines = SpanRenderer::fromMarkdown('**bold *italic* end**');

        // bold "bold ", bold+italic "italic", bold " end"
        self::assertCount(1, $lines);
        self::assertSame('italic', $lines[0]->spans[1]->content);
        self::assertSame(
            Attr::BOLD | Attr::ITALIC,
            $lines[0]->spans[1]->style->attributes,
            'Emphasis nested inside Strong must OR the italic attribute onto the inherited bold',
        );
    }

    #[Test]
    public function strikethroughInsideStrongCombinesBothAttributes(): void
    {
        $lines = SpanRenderer::fromMarkdown('**bold ~~struck~~ end**');

        self::assertCount(1, $lines);
        self::assertSame('struck', $lines[0]->spans[1]->content);
        self::assertSame(
            Attr::BOLD | Attr::STRIKETHROUGH,
            $lines[0]->spans[1]->style->attributes,
            'Strikethrough nested inside Strong must OR the strikethrough attribute onto the inherited bold',
        );
    }
}
