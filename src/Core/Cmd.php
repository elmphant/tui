<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Message\Message;

abstract readonly class Cmd
{
    public static function quit(): self
    {
        return new QuitCmd();
    }

    public static function tick(float $interval, ?EffectId $id = null): self
    {
        return new TickCmd($interval, $id);
    }

    /**
     * @param Closure(): (Message|Cmd|null) $then
     */
    public static function after(float $delay, Closure $then, ?EffectId $id = null): self
    {
        return new AfterCmd($delay, $then, $id);
    }

    public static function cancel(EffectId $id): self
    {
        return new CancelCmd($id);
    }

    public static function batch(self ...$commands): self
    {
        return new BatchCmd(array_values($commands));
    }

    /**
     * The one command in a collected list, a batch of several, or nothing at all.
     *
     * @param list<self> $commands
     */
    public static function combine(array $commands): ?self
    {
        return match (count($commands)) {
            0 => null,
            1 => $commands[0],
            default => self::batch(...$commands),
        };
    }

    public static function message(Message $message): self
    {
        return new MessageCmd($message);
    }

    /**
     * Run a short synchronous side effect.
     *
     * The callback may return a message, another command, or null. Blocking
     * work should use a dedicated asynchronous command such as exec() or
     * http() instead.
     *
     * @param Closure(): (Message|Cmd|null) $callback
     */
    public static function call(Closure $callback): self
    {
        return new CallCmd($callback);
    }

    /**
     * @param callable(Message): Message $wrap
     */
    public function map(callable $wrap): self
    {
        return new MappedCmd($this, $wrap(...));
    }

    /**
     * @param list<string> $command
     */
    public static function exec(array $command): ExecCmd
    {
        return new ExecCmd($command);
    }

    /**
     * @param list<string> $command
     */
    public static function process(array $command): ProcessCmd
    {
        return new ProcessCmd($command);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public static function http(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): HttpCmd {
        return new HttpCmd($url, $method, $headers, $body, options: $options);
    }
}
