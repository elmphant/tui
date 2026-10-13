<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph as CmParagraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use RuntimeException;

final class SpanRenderer
{
    /**
     * @return list<Line>
     */
    public static function fromMarkdown(string $markdown): array
    {
        if (!class_exists(Environment::class)) {
            throw new RuntimeException('league/commonmark is required for Markdown support. Install it via: composer require league/commonmark');
        }

        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new StrikethroughExtension());
        $parser = new MarkdownParser($environment);

        $document = $parser->parse($markdown);

        return self::renderDocument($document);
    }

    /**
     * @return list<Line>
     */
    private static function renderDocument(Document $document): array
    {
        $lines = [];

        foreach ($document->children() as $block) {
            array_push($lines, ...self::linesOf($block));
        }

        return $lines === [] ? [new Line([])] : $lines;
    }

    /**
     * @return list<Line>
     */
    private static function linesOf(Node $block): array
    {
        return match (true) {
            $block instanceof CmParagraph => [new Line(self::spansOf($block, 0))],
            $block instanceof Heading => [new Line(self::spansOf($block, Attr::BOLD, StyleToken::Title))],
            $block instanceof ListBlock => self::listLines($block),
            $block instanceof FencedCode, $block instanceof IndentedCode => self::codeLines($block->getLiteral()),
            $block instanceof BlockQuote => self::quoteLines($block),
            $block instanceof ThematicBreak => [new Line([Span::token('───', StyleToken::Dim)])],
            $block instanceof HtmlBlock => array_map(
                static fn (string $line): Line => new Line([Span::token($line, StyleToken::Dim)]),
                explode("\n", rtrim($block->getLiteral(), "\n")),
            ),
            default => [],
        };
    }

    /**
     * @return list<Line>
     */
    private static function listLines(ListBlock $list): array
    {
        $lines = [];
        $data = $list->getListData();
        $number = $data->start ?? 1;

        foreach ($list->children() as $item) {
            $marker = $data->type === ListBlock::TYPE_ORDERED ? ($number++) . '. ' : '• ';
            $prefix = $marker;
            foreach ($item->children() as $child) {
                foreach (self::linesOf($child) as $line) {
                    $lines[] = new Line([new Span($prefix), ...$line->spans]);
                    $prefix = str_repeat(' ', CharWidth::stringWidth($marker));
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<Line>
     */
    private static function codeLines(string $code): array
    {
        return array_map(
            static fn (string $line): Line => new Line([new Span('  '), Span::token(CharWidth::withTabsExpanded($line, 0), StyleToken::Accent)]),
            explode("\n", rtrim($code, "\n")),
        );
    }

    /**
     * @return list<Line>
     */
    private static function quoteLines(BlockQuote $quote): array
    {
        $lines = [];

        foreach ($quote->children() as $child) {
            foreach (self::linesOf($child) as $line) {
                $lines[] = new Line([Span::token('│ ', StyleToken::Dim), ...$line->spans]);
            }
        }

        return $lines;
    }

    /**
     * @return list<Span>
     */
    private static function spansOf(Node $node, int $attributes, ?StyleToken $token = null): array
    {
        $spans = [];

        foreach ($node->children() as $child) {
            array_push($spans, ...match (true) {
                $child instanceof Text => [new Span($child->getLiteral(), $attributes !== 0 ? new Style(attributes: $attributes) : null, $token)],
                $child instanceof Code => [new Span($child->getLiteral(), new Style(attributes: Attr::REVERSE))],
                $child instanceof Newline => [new Span(' ')],
                default => self::spansOf($child, $attributes | match (true) {
                    $child instanceof Strong => Attr::BOLD,
                    $child instanceof Emphasis => Attr::ITALIC,
                    $child instanceof Strikethrough => Attr::STRIKETHROUGH,
                    default => 0,
                }, $token),
            });
        }

        return $spans;
    }
}
