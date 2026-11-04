<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use WeakMap;

/**
 * The keys an App answers wherever the keyboard is: each one's keys, handler and label in one declaration.
 */
final readonly class AppActions
{
    /**
     * @param array<string, array{list<string>, Closure, ?string, bool}> $declared
     */
    private function __construct(private array $declared)
    {
    }

    /**
     * Ctrl+Q and Ctrl+C quit. Declare `Actions::QUIT` again to change the keys or to ask first.
     */
    public static function new(): self
    {
        return (new self([]))->with(Actions::QUIT, ['ctrl+q', 'ctrl+c'], static fn (App $app): UpdateResult => new UpdateResult($app, Cmd::quit()));
    }

    /**
     * The handler runs on the App that heard the key. A name declared again replaces the earlier declaration.
     * `inFooter: false` keeps it out of the footer, for a shortcut the key help alone should teach.
     *
     * @template TApp of App
     *
     * @param list<string>                             $keys
     * @param Closure(TApp): (TApp|UpdateResult<TApp>) $handler
     */
    public function with(string $name, array $keys, Closure $handler, ?string $label = null, bool $inFooter = true): self
    {
        Component::assertSafeReplacement($handler);

        return new self(declared: [...$this->declared, $name => [$keys, $handler, $label, $inFooter]]);
    }

    /**
     * Quit with these keys instead, keeping what quitting does.
     */
    public function withQuitKeys(string ...$keys): self
    {
        $quit = self::new()->declared[Actions::QUIT];
        [, $handler, $label, $inFooter] = $this->declared[Actions::QUIT] ?? $quit;

        return new self(declared: [...$this->declared, Actions::QUIT => [array_values($keys), $handler, $label, $inFooter]]);
    }

    /**
     * @internal
     */
    public function keybindings(): Keybindings
    {
        static $built = null;
        $built ??= new WeakMap();

        /** @var WeakMap<self, Keybindings> $built */
        return $built[$this] ??= new Keybindings(array_map(static fn (array $declared): array => $declared[0], $this->declared));
    }

    /**
     * @internal
     */
    public function footerKeybindings(): Keybindings
    {
        static $built = null;
        $built ??= new WeakMap();
        $shown = static fn (array $declared): bool => $declared[3];

        /** @var WeakMap<self, Keybindings> $built */
        return $built[$this] ??= new Keybindings(array_map(static fn (array $declared): array => $declared[0], array_filter($this->declared, $shown)));
    }

    /**
     * @internal
     */
    public function handlerOf(string $name): ?Closure
    {
        return ($this->declared[$name] ?? null)[1] ?? null;
    }

    /**
     * @internal
     */
    public function labelOf(string $name): ?string
    {
        return ($this->declared[$name] ?? null)[2] ?? null;
    }
}
