<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

interface Terminal extends TerminalOutput
{
    public function enableRawMode(): void;

    public function disableRawMode(): void;

    public function enterAltScreen(): void;

    public function leaveAltScreen(): void;

    public function hideCursor(): void;

    public function showCursor(): void;

    public function enableBracketedPaste(): void;

    public function disableBracketedPaste(): void;

    public function enableMouseTracking(): void;

    public function disableMouseTracking(): void;

    public function queryKittyProtocol(): void;

    public function enableKittyProtocol(): void;

    public function disableKittyProtocol(): void;

    /**
     * @return array{int, int} [width, height]
     */
    public function size(): array;

    /**
     * @return array{int, int}|null [width, height] or null on failure
     */
    public function probeSize(): ?array;

    public function colorProfile(): ColorProfile;

    public function detectColorScheme(): ?ColorScheme;

    /**
     * Null once the input is closed and everything sent before has been read.
     */
    public function readInput(): ?string;

    public function waitForInput(int $timeoutMicroseconds): bool;

    public function write(string $data): void;

    public function flush(): void;

    /**
     * @return resource|null Stream for EventLoop::onReadable(), or null if polling needed
     */
    public function inputStream(): mixed;
}
