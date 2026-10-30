<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Closure;
use Elmphant\Tui\Text\Grapheme;

/**
 * @internal stateful terminal-input framer consumed by Program
 */
final class StdinBuffer
{
    private const PASTE_START = "\x1b[200~";
    private const PASTE_END = "\x1b[201~";
    private const CONTINUATION_BYTES = "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf";
    public const MAX_PASTE_SIZE = 1048576;
    public const MAX_SEQUENCE_SIZE = 4096;
    public const PASTE_TIMEOUT = 1.0;
    private const REPLY_START = [']' => '/^\d+;/', 'P' => '/^[\d>=!]/', '_' => '/^G/'];

    /**
     * C0 bytes a control string cannot hold: one of them is a key the reader pressed.
     */
    private const NOT_IN_A_CONTROL_STRING = '/[\x00-\x06\x0e-\x1a\x1c-\x1f]/';

    private string $buffer = '';
    private bool $inPaste = false;
    private bool $pasteStalled = false;
    private string $pasteBuffer = '';
    private bool $inControlString = false;
    private bool $controlStringStalled = false;

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
            if ($this->inControlString) {
                if (!$this->droppedThroughTheControlStringEnd()) {
                    break;
                }
                continue;
            }

            if ($this->inPaste) {
                $endPos = strpos($this->buffer, self::PASTE_END);
                if ($endPos === false) {
                    $consumableLength = strlen($this->buffer) - $this->trailingPasteEndPrefixLength();
                    $remaining = self::MAX_PASTE_SIZE - strlen($this->pasteBuffer);
                    $this->pasteBuffer .= substr($this->buffer, 0, min($consumableLength, $remaining));
                    $this->buffer = substr($this->buffer, $consumableLength);
                    break;
                }
                $this->pasteBuffer .= substr($this->buffer, 0, min($endPos, self::MAX_PASTE_SIZE - strlen($this->pasteBuffer)));
                $this->buffer = substr($this->buffer, $endPos + 6);
                $this->inPaste = false;
                $this->pasteStalled = false;
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
                $continuation = strspn($this->buffer, self::CONTINUATION_BYTES, 1);
                if ($continuation < $expectedLength - 1 && 1 + $continuation < strlen($this->buffer)) {
                    ($this->onSequence)($this->buffer[0]);
                    $this->buffer = substr($this->buffer, 1);
                    continue;
                }
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
        if ($this->inControlString) {
            if ($this->controlStringStalled) {
                $this->inControlString = false;
                $this->buffer = '';
            }
            $this->controlStringStalled = !$this->controlStringStalled;

            return;
        }

        if ($this->inPaste && !$this->pasteStalled) {
            $this->pasteStalled = true;
            if ($this->pasteBuffer !== '' && $this->onPaste !== null) {
                ($this->onPaste)($this->pasteBuffer);
            }
            $this->pasteBuffer = '';

            return;
        }

        if ($this->inPaste) {
            $this->pasteBuffer .= $this->buffer;
            $this->buffer = '';
            $this->inPaste = false;
            $this->pasteStalled = false;
            if ($this->onPaste !== null) {
                ($this->onPaste)(substr($this->pasteBuffer, 0, self::MAX_PASTE_SIZE));
            }
            $this->pasteBuffer = '';

            return;
        }

        while ($this->buffer !== '' && $this->buffer[0] === "\x1b") {
            $second = ord($this->buffer[1] ?? "\x1b");
            $held = $second === 0x1B || $second >= 0x80 ? "\x1b" : substr($this->buffer, 0, 2);
            $rest = substr($this->buffer, strlen($held));
            $this->buffer = '';
            ($this->onSequence)($held);
            $this->process($rest);
        }
    }

    /**
     * Allow delayed escape sequences over SSH; incomplete UTF-8 waits without a timeout.
     * Give a paste two timeouts before releasing input, so a missing end marker cannot swallow keys forever.
     */
    public function flushDelay(): ?float
    {
        return match (true) {
            $this->inPaste => self::PASTE_TIMEOUT,
            $this->inControlString => $this->remote ? 0.1 : 0.05,
            $this->buffer === '' || $this->buffer[0] !== "\x1b" => null,
            $this->remote => 0.1,
            $this->buffer === "\x1b" => 0.01,
            default => 0.05,
        };
    }

    /**
     * Keep any suffix that could be part of PASTE_END, or a split marker would never end the paste.
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
     * Swallow OSC/DCS/APC replies as an empty string so their payload cannot become keystrokes.
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

        // Alt+] then typing looks like ESC ] too; only a payload shaped like a reply is held until its end.
        if (preg_match(self::REPLY_START[$this->buffer[1]], substr($this->buffer, 2)) === 1) {
            $this->inControlString = true;
            $this->controlStringStalled = false;

            return '';
        }

        return null;
    }

    /**
     * Drop what a reply sends until its BEL or ESC \, or up to a key it cannot hold; keep a trailing ESC that may start the ESC \.
     */
    private function droppedThroughTheControlStringEnd(): bool
    {
        $bell = strpos($this->buffer, "\x07");
        $stringTerminator = strpos($this->buffer, "\x1b\\");
        $key = preg_match(self::NOT_IN_A_CONTROL_STRING, $this->buffer, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : null;
        $ends = array_filter([$bell === false ? null : $bell + 1, $stringTerminator === false ? null : $stringTerminator + 2, $key], is_int(...));

        if ($ends === []) {
            $this->buffer = str_ends_with($this->buffer, "\x1b") ? "\x1b" : '';

            return false;
        }

        $this->buffer = substr($this->buffer, min($ends));
        $this->inControlString = false;

        return true;
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
