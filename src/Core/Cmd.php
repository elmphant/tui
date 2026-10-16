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
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\ListenCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;

abstract readonly class Cmd
{
    public static function quit(): self
    {
        return new QuitCmd();
    }

    /**
     * Puts the keyboard on a component. The tree owns which one that is, so an
     * app asks rather than setting a flag on the widget.
     */
    public static function focus(ComponentId $target): self
    {
        return new FocusCmd($target);
    }

    /**
     * Puts the keyboard where the tree begins, for an app that has just changed
     * what is on the screen and has somewhere but nowhere in particular to put it.
     */
    public static function focusFirst(): self
    {
        return new FocusCmd(move: FocusMove::First);
    }

    /**
     * Puts the keyboard down, so the app answers the keys itself until it hands
     * them back. The runtime leaves it down rather than settling on a component.
     */
    public static function focusNothing(): self
    {
        return new FocusCmd(move: FocusMove::Nowhere);
    }

    /**
     * Moves the keyboard one component along, wrapping at the end.
     */
    public static function focusNext(): self
    {
        return new FocusCmd(move: FocusMove::Next);
    }

    /**
     * Moves the keyboard one component back, wrapping at the start.
     */
    public static function focusPrevious(): self
    {
        return new FocusCmd(move: FocusMove::Previous);
    }

    /**
     * Asks the terminal to put the text on the system clipboard. It travels as an
     * escape sequence, so it crosses a container boundary or an ssh hop that a
     * clipboard library could not.
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
     * Wake on the stream becoming readable and turn what it yields into messages.
     *
     * Ends when the stream reaches EOF, or when $id is cancelled. Closing the
     * stream yourself needs `Cmd::cancel($id)` first, in that order: the driver
     * cannot select over a closed stream and takes the loop down with it.
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
     * @param Closure():((Message|Cmd|null)) $callback
     */
    public static function call(Closure $callback): self
    {
        return new CallCmd($callback);
    }

    /**
     * @param callable(Message):Message $wrap
     */
    public function map(callable $wrap): self
    {
        return new MappedCmd($this, $wrap(...));
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
