<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Core\Cmd\AfterCmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\CallCmd;
use Elmphant\Tui\Core\Cmd\CancelCmd;
use Elmphant\Tui\Core\Cmd\ClipboardCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\FiberCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\HttpMethod;
use Elmphant\Tui\Core\Cmd\ListenCmd;
use Elmphant\Tui\Core\Cmd\LogCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\PrintlnCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;
use Elmphant\Tui\Core\Cmd\StreamCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\Cmd\ToastCmd;

abstract readonly class Cmd
{
    public static function quit(): self
    {
        return new QuitCmd();
    }

    /**
     * Write to the trace because stdout belongs to the terminal display.
     *
     * @param array<string, scalar|null> $context
     */
    public static function log(string $message, array $context = []): self
    {
        return new LogCmd($message, $context);
    }

    /**
     * Request focus through the tree rather than setting a widget flag.
     */
    public static function focus(ComponentId|string $target): self
    {
        return FocusCmd::to(is_string($target) ? ComponentId::named($target) : $target);
    }

    /**
     * Focus the first component after changing the view.
     */
    public static function focusFirst(): self
    {
        return FocusCmd::first();
    }

    /**
     * Clear focus until the app explicitly moves it again.
     */
    public static function focusNowhere(): self
    {
        return FocusCmd::nowhere();
    }

    public static function focusNext(): self
    {
        return FocusCmd::next();
    }

    public static function focusPrevious(): self
    {
        return FocusCmd::previous();
    }

    /**
     * The runtime holds the toast, so the model keeps nothing.
     */
    public static function toast(string $text): ToastCmd
    {
        return new ToastCmd($text);
    }

    /**
     * Lines that stay above an inline region, in the shell's scrollback.
     */
    public static function println(string ...$lines): self
    {
        return new PrintlnCmd(array_values($lines));
    }

    /**
     * OSC 52 reaches the user's clipboard through containers and SSH sessions.
     */
    public static function copy(string $text): self
    {
        return new ClipboardCmd($text);
    }

    public static function tick(float $interval, ?EffectId $id = null): self
    {
        return new TickCmd($interval, $id);
    }

    /**
     * @param Closure():((Message|Cmd|null)) $then
     */
    public static function after(float $delay, Closure $then, ?EffectId $id = null): self
    {
        return new AfterCmd($delay, $then, $id);
    }

    public static function cancel(EffectId $id): self
    {
        return new CancelCmd($id);
    }

    /**
     * Read messages until EOF or cancellation.
     * Cancel before closing the stream: the event-loop driver cannot watch a closed stream.
     *
     * @param resource                            $stream
     * @param Closure(resource):iterable<Message> $onReadable
     */
    public static function listen(mixed $stream, Closure $onReadable, ?EffectId $id = null): self
    {
        return new ListenCmd($stream, $onReadable, $id);
    }

    /**
     * Null when no command is there, the command itself when one is.
     */
    public static function batch(?self ...$commands): ?self
    {
        $flattened = [];
        foreach ($commands as $command) {
            if ($command instanceof BatchCmd) {
                array_push($flattened, ...$command->commands);
            } elseif ($command !== null) {
                $flattened[] = $command;
            }
        }

        return match (count($flattened)) {
            0 => null,
            1 => $flattened[0],
            default => new BatchCmd($flattened),
        };
    }

    public static function message(Message $message): self
    {
        return new MessageCmd($message);
    }

    /**
     * Run a short synchronous callback; use exec() or http() for blocking work.
     * The callback may return a message, command or null.
     *
     * @param Closure():((Message|Cmd|null)) $callback
     */
    public static function call(Closure $callback): self
    {
        return new CallCmd($callback);
    }

    /**
     * Runs in a fiber of its own: while the work waits on I/O that suspends the fiber, the program keeps drawing.
     * Work that blocks instead (curl, file_get_contents, a long loop) freezes the screen as Cmd::call() does.
     *
     * @param Closure(): ?Message $work
     */
    public static function fiber(Closure $work, ?EffectId $id = null): self
    {
        return new FiberCmd($work, $id);
    }

    /**
     * Runs blocking work (network calls, database queries, a long computation) and answers with what it returns.
     * $work gets a $send that hands a message on at once, and returns its last message or null.
     *
     * @param Closure(Closure(Message): void): ?Message $work
     */
    public static function fork(Closure $work, ?EffectId $id = null): self
    {
        return new ForkCmd($work, $id);
    }

    /**
     * Without process control (Windows, the official Docker images and Alpine unless it is installed),
     * Cmd::fork() runs its work in the program itself, and the screen holds still until it is done.
     */
    public static function canFork(): bool
    {
        return \PHP_SAPI === 'cli' && \function_exists('pcntl_fork');
    }

    /**
     * @param callable(Message):Message $wrap
     */
    public function map(callable $wrap): self
    {
        return new MappedCmd($this, $wrap(...));
    }

    /**
     * Effect names are local to this scope, including replacement and cancellation.
     */
    public function scopedTo(string $scope): self
    {
        return new ScopedCmd($this, $scope);
    }

    /**
     * @param list<string> $command
     */
    public static function exec(array $command, ?EffectId $id = null): ExecCmd
    {
        return new ExecCmd($command, $id);
    }

    /**
     * @param list<string> $command
     */
    public static function stream(array $command, ?EffectId $id = null): StreamCmd
    {
        return new StreamCmd($command, $id);
    }

    /**
     * Headers, a body and client options are set with the HttpCmd's withHeaders(), withBody() and withOptions().
     */
    public static function http(string $url, HttpMethod $method = HttpMethod::Get, ?EffectId $id = null): HttpCmd
    {
        return new HttpCmd($url, $method, id: $id);
    }
}
