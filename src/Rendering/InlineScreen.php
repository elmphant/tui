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
    private const ATTRIBUTE_CODES = [
        Attr::BOLD => '1',
        Attr::FAINT => '2',
        Attr::ITALIC => '3',
        Attr::UNDERLINE => '4',
        Attr::BLINK => '5',
        Attr::REVERSE => '7',
        Attr::STRIKETHROUGH => '9',
    ];

    /**
     * @var list<int> the cells written on each row
     */
    private array $written = [];
    private int $width = 0;
    private bool $printedLast = false;

    public function __construct(
        private readonly TerminalOutput $terminal,
        private readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
    }

    public function flush(Frame $backBuffer): void
    {
        $caret = $backBuffer->cursorPosition === null ? -1 : $backBuffer->cursorPosition->row * $backBuffer->width + $backBuffer->cursorPosition->col;
        $rows = [];
        $written = [];
        for ($row = 0; $row < $backBuffer->height; ++$row) {
            $rows[] = $this->rowText($backBuffer, $row, $caret);
            $written[] = self::endOf($backBuffer, $row, $caret) - $row * $backBuffer->width;
        }

        $taller = $backBuffer->height < $this->rowsTaken($backBuffer->width);
        // The hidden cursor rests at the start of the first row. A terminal that rewraps on a narrower width keeps it there.
        $painted = "\r" . implode("\r\n", $rows)
            . ($taller ? "\r\n" . Ansi::ERASE_BELOW . self::up($backBuffer->height) : self::up($backBuffer->height - 1))
            . "\r";
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
        $printed = "\r" . Ansi::ERASE_BELOW;
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
        $style = '';
        for ($index = $offset; $index < $end; ++$index) {
            $cellStyle = $this->styleOf($frame, $index, $index === $caret);
            if ($cellStyle !== $style) {
                $text .= $cellStyle === '' ? Ansi::RESET_STYLE : Ansi::sgr('0;' . $cellStyle);
                $style = $cellStyle;
            }
            $text .= $frame->characters[$index];
        }

        $text = $style === '' ? $text : $text . Ansi::RESET_STYLE;

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

    private function styleOf(Frame $frame, int $index, bool $caret): string
    {
        $foreground = $frame->foregrounds[$index];
        $background = $frame->backgrounds[$index];
        $attributes = $frame->attributes[$index] ^ ($caret ? Attr::REVERSE : 0);
        if ($foreground === 0 && $background === 0 && $attributes === 0) {
            return '';
        }

        $codes = [];
        foreach (self::ATTRIBUTE_CODES as $attribute => $code) {
            if (($attributes & $attribute) !== 0) {
                $codes[] = $code;
            }
        }
        $codes[] = Color::fromValue($foreground)->downsampleTo($this->colorProfile)->toSgr(false);
        $codes[] = Color::fromValue($background)->downsampleTo($this->colorProfile)->toSgr(true);

        return implode(';', $codes);
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
