<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\SpanRenderer;
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
    public function headingIsSkippedProducingEmptyLine(): void
    {
        $lines = SpanRenderer::fromMarkdown('# Heading');

        self::assertCount(1, $lines);
        self::assertCount(0, $lines[0]->spans);
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
    public function headingDoesNotStopIterationOverSubsequentParagraphs(): void
    {
        $lines = SpanRenderer::fromMarkdown("# Heading\n\nParagraph body");

        self::assertCount(1, $lines);
        self::assertCount(1, $lines[0]->spans);
        self::assertSame('Paragraph body', $lines[0]->spans[0]->content, 'Non-paragraph blocks must be skipped with `continue`, not break iteration');
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
