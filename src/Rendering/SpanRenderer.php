<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
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
            if (!$block instanceof CmParagraph) {
                continue;
            }

            $currentSpans = [];
            self::walkInlines($block, $currentSpans, 0);
            $lines[] = new Line($currentSpans);
        }

        if ($lines === []) {
            $lines[] = new Line([]);
        }

        return $lines;
    }

    /**
     * @param list<Span> $spans
     */
    private static function walkInlines(Node $node, array &$spans, int $attributes): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $style = $attributes !== 0 ? new Style(attributes: $attributes) : null;
                $spans[] = new Span($child->getLiteral(), $style);
                continue;
            }

            if ($child instanceof Code) {
                $spans[] = new Span($child->getLiteral(), new Style(attributes: Attr::REVERSE));
                continue;
            }

            if ($child instanceof Newline) {
                $spans[] = new Span(' ');
                continue;
            }

            $childAttributes = $attributes;
            if ($child instanceof Strong) {
                $childAttributes |= Attr::BOLD;
            } elseif ($child instanceof Emphasis) {
                $childAttributes |= Attr::ITALIC;
            } elseif ($child instanceof Strikethrough) {
                $childAttributes |= Attr::STRIKETHROUGH;
            }

            self::walkInlines($child, $spans, $childAttributes);
        }
    }
}
