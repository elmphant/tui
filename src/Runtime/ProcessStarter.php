<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Message\Message;
use Symfony\Component\Process\Process;

/**
 * Spawns the child process a command asked for. Throws where it cannot, so what
 * a failure to start is worth saying stays with the caller.
 *
 * @internal
 */
final class ProcessStarter
{
    /**
     * @param Closure(Message): Message|null $wrap
     */
    public function exec(ExecCmd $command, ?Closure $wrap): RunningProcess
    {
        return new RunningExecProcess(
            $this->started($command->command, $command->workingDirectory, $command->timeout, $command->env, $command->input),
            $command->id,
            $command->maxOutputBytes,
            $wrap,
        );
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    public function streaming(ProcessCmd $command, ?Closure $wrap): RunningProcess
    {
        return new RunningStreamingProcess(
            $this->started($command->command, $command->workingDirectory, $command->timeout, $command->env, $command->input),
            $command->id,
            $command->outputChunkBytes,
            $wrap,
        );
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function started(
        array $command,
        ?string $workingDirectory,
        ?float $timeout,
        array $env,
        ?string $input,
    ): Process {
        $process = new Process($command);

        if ($workingDirectory !== null) {
            $process->setWorkingDirectory($workingDirectory);
        }
        if ($timeout !== null) {
            $process->setTimeout($timeout);
        }
        if ($env !== []) {
            $process->setEnv($env);
        }
        if ($input !== null) {
            $process->setInput($input);
        }

        $process->start();

        return $process;
    }
}
