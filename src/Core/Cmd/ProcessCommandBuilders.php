<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\EffectId;

/**
 * The builders ExecCmd and ProcessCmd share; each keeps only the output-sizing
 * field that distinguishes it.
 *
 * @internal
 */
trait ProcessCommandBuilders
{
    /**
     * @param list<string> $command
     */
    public function withCommand(array $command): static
    {
        return $this->cloneWith(command: $command);
    }

    public function withId(?EffectId $id): static
    {
        return $this->cloneWith(id: $id);
    }

    public function withWorkingDirectory(?string $workingDirectory): static
    {
        return $this->cloneWith(workingDirectory: $workingDirectory);
    }

    public function withTimeout(?float $timeout): static
    {
        return $this->cloneWith(timeout: $timeout);
    }

    /**
     * @param array<string, string> $env
     */
    public function withEnv(array $env): static
    {
        return $this->cloneWith(env: $env);
    }

    public function withInput(?string $input): static
    {
        return $this->cloneWith(input: $input);
    }
}
