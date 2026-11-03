<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Support\Cloneable;
use InvalidArgumentException;

final readonly class ProcessCmd extends Cmd implements NamesItsEffect
{
    use Cloneable;
    use ProcessCommandBuilders;

    /**
     * @internal build one with Cmd::process()
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    public function __construct(
        public array $command,
        public ?EffectId $id = null,
        public ?string $workingDirectory = null,
        public ?float $timeout = null,
        public array $env = [],
        public ?string $input = null,
        public int $outputChunkBytes = 65_536,
        public bool $framesLines = false,
    ) {
        if ($command === []) {
            throw new InvalidArgumentException('ProcessCmd requires a non-empty command array');
        }
        if ($outputChunkBytes < 1) {
            throw new InvalidArgumentException('ProcessCmd outputChunkBytes must be positive');
        }
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }

    public function withOutputChunkBytes(int $outputChunkBytes): self
    {
        return $this->cloneWith(outputChunkBytes: $outputChunkBytes);
    }

    /**
     * Emit complete lines, including a final line without a newline when the process ends.
     * A line that grows past the chunk size is sent before it ends.
     */
    public function withLineFraming(bool $framesLines = true): self
    {
        return $this->cloneWith(framesLines: $framesLines);
    }
}
