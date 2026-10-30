<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Message;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class ProcessStarter
{
    public function __construct(private readonly ?Clock $clock = null)
    {
    }

    /**
     * @param Closure(Message):Message|null $wrap
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
     * @param Closure(Message):Message|null $wrap
     */
    public function streaming(ProcessCmd $command, ?Closure $wrap): RunningProcess
    {
        return new RunningStreamingProcess(
            $this->started($command->command, $command->workingDirectory, $command->timeout, $command->env, $command->input),
            $command->id,
            $command->outputChunkBytes,
            $wrap,
            $command->framesLines,
            $this->clock,
        );
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    public static function processFor(
        array $command,
        ?string $workingDirectory,
        ?float $timeout,
        array $env,
        ?string $input,
    ): Process {
        return new Process($command, $workingDirectory, $env === [] ? null : $env, $input, $timeout);
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
        $process = self::processFor($command, $workingDirectory, $timeout, $env, $input);
        $process->start();

        return $process;
    }
}
