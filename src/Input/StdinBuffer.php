<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Closure;
use Elmphant\Tui\Rendering\Grapheme;

/**
 * @internal stateful terminal-input framer consumed by Program
 */
final class StdinBuffer
{
    private const PASTE_START = "\x1b[200~";
    private const PASTE_END = "\x1b[201~";
    public const MAX_PASTE_SIZE = 1048576;
    public const MAX_SEQUENCE_SIZE = 4096;

    private string $buffer = '';
    private bool $inPaste = false;
    private string $pasteBuffer = '';

    /**
     * @param Closure(string): void $onSequence
     */
    public function __construct(
        private readonly Closure $onSequence,
        private readonly ?Closure $onPaste = null,
        private readonly bool $remote = false,
    ) {
    }

    public function process(string $data): void
    {
        if (!$this->inPaste && strlen($data) === 1 && ord($data) > 127 && (ord($data) < 0xC2 || ord($data) > 0xF4) && $this->buffer === '') {
            $data = "\x1b" . chr(ord($data) - 128);
        }
        $this->buffer .= $data;

        while ($this->buffer !== '') {
            if ($this->inPaste) {
                $endPos = strpos($this->buffer, self::PASTE_END);
                if ($endPos === false) {
                    $consumableLength = strlen($this->buffer) - $this->trailingPasteEndPrefixLength();
                    $remaining = self::MAX_PASTE_SIZE - strlen($this->pasteBuffer);
                    if ($remaining > 0 && $consumableLength > 0) {
                        $this->pasteBuffer .= substr($this->buffer, 0, min($consumableLength, $remaining));
                    }
                    $this->buffer = substr($this->buffer, $consumableLength);
                    break;
                }
                $remaining = self::MAX_PASTE_SIZE - strlen($this->pasteBuffer);
                if ($remaining > 0) {
                    $this->pasteBuffer .= substr($this->buffer, 0, min($endPos, $remaining));
                }
                $this->buffer = substr($this->buffer, $endPos + 6);
                $this->inPaste = false;
                if ($this->onPaste !== null) {
                    ($this->onPaste)($this->pasteBuffer);
                }
                $this->pasteBuffer = '';
                continue;
            }

            if (str_starts_with($this->buffer, self::PASTE_START)) {
                $this->inPaste = true;
                $this->pasteBuffer = '';
                $this->buffer = substr($this->buffer, 6);
                continue;
            }

            $byte = ord($this->buffer[0]);

            if ($byte === 0x1B) {
                $extracted = $this->extractEscapeSequence();
                if ($extracted === null) {
                    if (strlen($this->buffer) > self::MAX_SEQUENCE_SIZE) {
                        $this->buffer = '';
                    }
                    break;
                }
                if ($extracted !== '') {
                    ($this->onSequence)($extracted);
                }
                continue;
            }

            if ($byte >= 0xC0) {
                $expectedLength = Grapheme::sequenceLength($byte);
                if (strlen($this->buffer) < $expectedLength) {
                    break;
                }
                ($this->onSequence)(substr($this->buffer, 0, $expectedLength));
                $this->buffer = substr($this->buffer, $expectedLength);
                continue;
            }

            ($this->onSequence)($this->buffer[0]);
            $this->buffer = substr($this->buffer, 1);
        }
    }

    public function flush(): void
    {
        if ($this->buffer === "\x1b\x1b") {
            ($this->onSequence)("\x1b");
            $this->buffer = "\x1b";
        }

        if ($this->buffer !== '') {
            ($this->onSequence)($this->buffer);
            $this->buffer = '';
        }
    }

    /**
     * Over ssh the rest of an arrow key can arrive well after its ESC.
     */
    public function flushDelay(): ?float
    {
        return match ($this->buffer) {
            '' => null,
            "\x1b" => $this->remote ? 0.1 : 0.01,
            default => 0.05,
        };
    }

    /**
     * A read can split PASTE_END, so a tail that could still grow into it stays buffered
     * rather than being swallowed as paste content — otherwise the paste never terminates.
     */
    private function trailingPasteEndPrefixLength(): int
    {
        $longestPrefix = min(strlen($this->buffer), strlen(self::PASTE_END) - 1);

        for ($length = $longestPrefix; $length > 0; --$length) {
            if (substr($this->buffer, -$length) === substr(self::PASTE_END, 0, $length)) {
                return $length;
            }
        }

        return 0;
    }

    private function extractEscapeSequence(): ?string
    {
        $length = strlen($this->buffer);

        if ($length < 2) {
            return null;
        }

        $second = $this->buffer[1];

        if ($second === '[') {
            return $this->extractCsiSequence();
        }

        if ($second === 'O') {
            return $this->extractSs3Sequence();
        }

        if ($second === ']' || $second === 'P' || $second === '_') {
            return $this->extractStringSequence();
        }

        if ($second === "\x1b") {
            if ($length < 3) {
                return null;
            }
            $third = $this->buffer[2];
            if ($third === '[' || $third === 'O' || $third === ']' || $third === 'P' || $third === '_') {
                $this->buffer = substr($this->buffer, 1);

                return "\x1b";
            }
            $this->buffer = substr($this->buffer, 2);
            ($this->onSequence)("\x1b");

            return "\x1b";
        }

        if (ord($second) < 0x80) {
            $sequence = substr($this->buffer, 0, 2);
            $this->buffer = substr($this->buffer, 2);

            return $sequence;
        }

        return null;
    }

    /**
     * OSC/DCS/APC terminal replies are consumed without emission ('' = swallowed):
     * no consumer exists yet, and forwarding them would leak the payload as keystrokes.
     */
    private function extractStringSequence(): ?string
    {
        $length = strlen($this->buffer);
        $position = 2;
        while ($position < $length) {
            $byte = ord($this->buffer[$position]);

            if ($byte === 0x07) {
                $this->buffer = substr($this->buffer, $position + 1);

                return '';
            }

            if ($byte === 0x1B && $position + 1 < $length && $this->buffer[$position + 1] === '\\') {
                $this->buffer = substr($this->buffer, $position + 2);

                return '';
            }

            ++$position;
        }

        return null;
    }

    private function extractSs3Sequence(): ?string
    {
        if (strlen($this->buffer) < 3) {
            return null;
        }

        $sequence = substr($this->buffer, 0, 3);
        $this->buffer = substr($this->buffer, 3);

        return $sequence;
    }

    private function extractCsiSequence(): ?string
    {
        $length = strlen($this->buffer);
        $position = 2;

        while ($position < $length) {
            $byte = ord($this->buffer[$position]);

            if ($byte >= 0x40 && $byte <= 0x7E) {
                // X10 mouse: "\x1b[M" carries three raw payload bytes after the final byte.
                if ($byte === 0x4D && $position === 2) {
                    if ($length < 6) {
                        return null;
                    }
                    $sequence = substr($this->buffer, 0, 6);
                    $this->buffer = substr($this->buffer, 6);

                    return $sequence;
                }
                $sequence = substr($this->buffer, 0, $position + 1);
                $this->buffer = substr($this->buffer, $position + 1);

                return $sequence;
            }

            ++$position;
        }

        return null;
    }
}
