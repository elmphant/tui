<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use InvalidArgumentException;

final readonly class ExecCmd extends Cmd implements NamesItsEffect
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
        public int $maxOutputBytes = 8_388_608,
    ) {
        if ($command === []) {
            throw new InvalidArgumentException('ExecCmd requires a non-empty command array');
        }
        if ($maxOutputBytes < 1) {
            throw new InvalidArgumentException('ExecCmd maxOutputBytes must be positive');
        }
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }

    public function withMaxOutputBytes(int $maxOutputBytes): self
    {
        return $this->cloneWith(maxOutputBytes: $maxOutputBytes);
    }
}
