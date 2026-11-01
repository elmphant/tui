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
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\ListenCmd;
use Elmphant\Tui\Core\Cmd\LogCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\PrintlnCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;
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
        return new FocusCmd(is_string($target) ? ComponentId::named($target) : $target);
    }

    /**
     * Focus the first component after changing the view.
     */
    public static function focusFirst(): self
    {
        return new FocusCmd(move: FocusMove::First);
    }

    /**
     * Clear focus until the app explicitly moves it again.
     */
    public static function focusNothing(): self
    {
        return new FocusCmd(move: FocusMove::Nowhere);
    }

    public static function focusNext(): self
    {
        return new FocusCmd(move: FocusMove::Next);
    }

    public static function focusPrevious(): self
    {
        return new FocusCmd(move: FocusMove::Previous);
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

    public static function batch(self ...$commands): self
    {
        $flattened = [];
        foreach ($commands as $command) {
            if ($command instanceof BatchCmd) {
                array_push($flattened, ...$command->commands);

                continue;
            }

            $flattened[] = $command;
        }

        return new BatchCmd($flattened);
    }

    public static function combine(?self ...$commands): ?self
    {
        $commands = array_values(array_filter($commands));

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
    public static function process(array $command, ?EffectId $id = null): ProcessCmd
    {
        return new ProcessCmd($command, $id);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options passed to the HTTP client; past $options['timeout'] seconds without data the effect resolves as an ErrorMsg instead of idling forever, and $options['max_duration'] bounds the whole request
     */
    public static function http(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        array $options = [],
        ?EffectId $id = null,
    ): HttpCmd {
        return new HttpCmd($url, $method, $headers, $body, $id, $options);
    }
}
