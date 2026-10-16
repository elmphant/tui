<?php

declare(strict_types=1);

namespace Elmphant\Tui\Terminal;

interface TerminalOutput
{
    /**
     * @throws TerminalWriteException when the complete payload cannot be written
     */
    public function write(string $data): void;

    /**
     * @throws TerminalException when buffered output cannot be flushed
     */
    public function flush(): void;
}
