<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Input\KeyHint;
use Elmphant\Tui\Search\SubstringMatcher;
use Elmphant\Tui\Text\CharWidth;

/**
 * @internal the lines of the key help: a group title, an entry, or null for the gap between groups
 */
final readonly class KeyHelpRows
{
    public const MARKER_WIDTH = 2;
    public const GAP = 2;

    /**
     * @param list<KeyHint|string|null> $lines
     */
    private function __construct(public array $lines)
    {
    }

    /**
     * @param array<array-key, list<KeyHint>> $groups
     */
    public static function of(array $groups, string $query): self
    {
        $matcher = new SubstringMatcher();
        $lines = [];

        foreach ($groups as $title => $entries) {
            if ($query !== '') {
                $haystack = array_map(static fn (KeyHint $entry): string => $entry->description . ' ' . $entry->label, $entries);
                $entries = array_map(static fn (int $index): KeyHint => $entries[$index], $matcher->matching($query, $haystack));
            }

            if ($entries === []) {
                continue;
            }

            if ($lines !== []) {
                $lines[] = null;
            }
            $lines[] = (string)$title;
            array_push($lines, ...$entries);
        }

        return new self($lines);
    }

    /**
     * @return list<KeyHint>
     */
    public function entries(): array
    {
        return array_values(array_filter($this->lines, static fn (KeyHint|string|null $line): bool => $line instanceof KeyHint));
    }

    /**
     * The group title's line when the entry is the first in its group.
     */
    public function firstLineShowing(KeyHint $entry): int
    {
        $line = $this->lineOf($entry);

        return \is_string($this->lines[$line - 1] ?? null) ? $line - 1 : $line;
    }

    public function lineOf(KeyHint $entry): int
    {
        return (int)array_search($entry, $this->lines, true);
    }

    public function width(): int
    {
        $width = 0;
        foreach ($this->lines as $line) {
            $width = max($width, match (true) {
                $line instanceof KeyHint => self::MARKER_WIDTH + CharWidth::stringWidth($line->description) + self::GAP + CharWidth::stringWidth($line->label),
                \is_string($line) => CharWidth::stringWidth($line),
                default => 0,
            });
        }

        return $width;
    }
}
