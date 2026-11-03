<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\TerminalOutput;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TerminalText;

final class InlineScreen
{
    // tmux copies the whole screen into its history when an erase starts at the top left, so it starts one column in.
    private const ERASE_REGION = "\x1b[1C" . Ansi::ERASE_BELOW . "\r";

    /**
     * @var list<int> the cells written on each row
     */
    private array $written = [];
    private int $width = 0;
    private bool $printedLast = false;
    private readonly Pen $pen;

    public function __construct(
        private readonly TerminalOutput $terminal,
        ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
        $this->pen = new Pen($colorProfile);
    }

    /**
     * @param ?int $height the rows to paint from the top of the frame, all of them when null
     */
    public function flush(Frame $backBuffer, ?int $height = null): void
    {
        $height = min($height ?? $backBuffer->height, $backBuffer->height);
        $cursor = $backBuffer->cursorPosition();
        $caret = $cursor === null ? -1 : $cursor->row * $backBuffer->width + $cursor->col;
        $rows = [];
        $written = [];
        for ($row = 0; $row < $height; ++$row) {
            $rows[] = $this->rowText($backBuffer, $row, $caret);
            $written[] = self::endOf($backBuffer, $row, $caret) - $row * $backBuffer->width;
        }

        // A wider terminal rejoins wrapped lines above the region and lifts it, leaving its old last rows below.
        $taller = $height < $this->rowsTaken($backBuffer->width) || ($this->written !== [] && $backBuffer->width > $this->width);
        // The hidden cursor rests at the start of the first row. A terminal that rewraps on a narrower width keeps it there.
        $painted = "\r" . ($taller ? self::ERASE_REGION : '') . implode("\r\n", $rows) . self::up($height - 1) . "\r";
        $this->written = $written;
        $this->width = $backBuffer->width;
        $this->printedLast = false;

        $this->terminal->write(Ansi::SYNC_START . $painted . Ansi::SYNC_END);
        $this->terminal->flush();
    }

    /**
     * @param list<string> $lines
     */
    public function printAbove(array $lines, int $width): void
    {
        $printed = "\r" . self::ERASE_REGION;
        foreach (explode("\n", implode("\n", $lines)) as $line) {
            $printed .= CharWidth::truncateToWidth(TerminalText::plain($line), $width) . "\r\n";
        }
        $this->written = [];
        $this->printedLast = true;

        $this->terminal->write(Ansi::SYNC_START . $printed . Ansi::SYNC_END);
        $this->terminal->flush();
    }

    public function printedLast(): bool
    {
        return $this->printedLast;
    }

    public function parkBelow(): string
    {
        if ($this->written === []) {
            return "\r";
        }

        return self::down(\count($this->written) - 1) . "\r\n";
    }

    private function rowsTaken(int $width): int
    {
        if ($width >= $this->width) {
            return \count($this->written);
        }

        return array_sum(array_map(static fn (int $cells): int => max(1, intdiv($cells + $width - 1, $width)), $this->written));
    }

    private function rowText(Frame $frame, int $row, int $caret): string
    {
        $offset = $row * $frame->width;
        $end = self::endOf($frame, $row, $caret);

        $text = '';
        for ($index = $offset; $index < $end; ++$index) {
            $column = $index - $offset;
            $width = $frame->widths[$index];
            $text .= $this->pen->switchTo($frame->foregrounds[$index], $frame->backgrounds[$index], $frame->attributes[$index] ^ ($index === $caret ? Attr::REVERSE : 0))
                . Pen::cellText($frame->characters[$index], $width, "\r" . self::right($column), "\r" . self::right($column + $width));
        }

        $text .= $this->pen->lift();

        // A row written to its last column leaves the cursor on that column, so an erase would take the last character.
        return $end === $offset + $frame->width ? $text : $text . Ansi::ERASE_TO_LINE_END;
    }

    private static function endOf(Frame $frame, int $row, int $caret): int
    {
        $offset = $row * $frame->width;
        $end = $offset + $frame->width;
        while ($end > $offset && $end - 1 !== $caret && self::isBlank($frame, $end - 1)) {
            --$end;
        }

        return $end;
    }

    private static function isBlank(Frame $frame, int $index): bool
    {
        return $frame->characters[$index] === ' ' && $frame->backgrounds[$index] === 0 && $frame->attributes[$index] === 0;
    }

    private static function right(int $columns): string
    {
        return $columns > 0 ? Ansi::cursorRight($columns) : '';
    }

    private static function up(int $rows): string
    {
        return $rows > 0 ? Ansi::cursorUp($rows) : '';
    }

    private static function down(int $rows): string
    {
        return $rows > 0 ? Ansi::cursorDown($rows) : '';
    }
}
