<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\Message;
use Throwable;
use UnexpectedValueException;

/**
 * @internal what a forked process does: run the work, write what it said to the parent, and end without shutting down
 *
 * @infection-ignore-all runs only in a forked child, which no mutation test can observe
 */
final class ForkChild
{
    /**
     * The terminal belongs to the parent: the child must never draw, read keys or restore the terminal.
     *
     * @param resource $socket
     */
    public static function run(ForkCmd $command, $socket): never
    {
        self::forgetTheParentsSignals();
        $quiet = self::silenceTheTerminal();
        stream_set_blocking($socket, true);

        $send = static function (Message $message) use ($socket): void {
            self::write($socket, ForkFrame::Message->encode(serialize($message)));
        };

        try {
            $result = ($command->work)($send);
            if ($result !== null && !$result instanceof Message) {
                throw new UnexpectedValueException('Cmd::fork() work must return a Message or null');
            }
            if ($result !== null) {
                $send($result);
            }
        } catch (Throwable $error) {
            self::write($socket, ForkFrame::Failure->encode(serialize([$error::class, $error->getMessage(), $error->getTraceAsString()])));
        }

        self::write($socket, ForkFrame::End->encode());
        unset($quiet);
        self::endWithoutShuttingDown();
    }

    /**
     * The program's handlers only queue work for a loop the child never runs again, so a SIGTERM would not end it.
     */
    private static function forgetTheParentsSignals(): void
    {
        foreach ([\SIGTERM, \SIGINT, \SIGHUP, \SIGWINCH, \SIGTSTP, \SIGCONT, \SIGQUIT, \SIGUSR1, \SIGUSR2] as $signal) {
            pcntl_signal($signal, \SIG_DFL);
        }
    }

    /**
     * Opening a file takes the lowest free descriptor, so these take the places of standard input, output and error.
     *
     * @return list<resource|false>
     */
    private static function silenceTheTerminal(): array
    {
        fclose(\STDIN);
        fclose(\STDOUT);
        fclose(\STDERR);

        return [fopen('/dev/null', 'r'), fopen('/dev/null', 'w'), fopen('/dev/null', 'w')];
    }

    /**
     * @param resource $socket
     */
    private static function write($socket, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                return;
            }
            $bytes = substr($bytes, $written);
        }
    }

    /**
     * Shutdown functions and destructors belong to the parent: they would restore its terminal and close its connections.
     */
    private static function endWithoutShuttingDown(): never
    {
        if (\function_exists('posix_kill')) {
            posix_kill(posix_getpid(), \SIGKILL);
        }

        pcntl_exec('/bin/sh', ['-c', 'exit 0']);

        exit(0);
    }
}
