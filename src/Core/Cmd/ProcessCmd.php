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
}
