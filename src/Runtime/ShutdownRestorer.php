<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use SplObjectStorage;

/**
 * @internal
 */
final class ShutdownRestorer
{
    /**
     * @var SplObjectStorage<TerminalSession, null>|null
     */
    private static ?SplObjectStorage $sessions = null;
    private static bool $hookRegistered = false;
    /**
     * @var Closure(Closure(): void): void|null
     */
    private static ?Closure $hookRegistrar = null;

    public static function register(TerminalSession $session): void
    {
        self::$sessions ??= new SplObjectStorage();
        self::$sessions->offsetSet($session, null);

        if (!self::$hookRegistered) {
            $registerHook = self::$hookRegistrar ?? register_shutdown_function(...);
            $registerHook(self::restoreAll(...));
            self::$hookRegistered = true;
        }
    }

    /**
     * Test seam: replaces register_shutdown_function so hook registration is observable
     * without an actual process shutdown.
     *
     * @param Closure(Closure(): void): void|null $hookRegistrar
     */
    public static function overrideHookRegistrar(?Closure $hookRegistrar): void
    {
        self::$hookRegistrar = $hookRegistrar;
    }

    public static function unregister(TerminalSession $session): void
    {
        if (self::$sessions?->offsetExists($session) === true) {
            self::$sessions->offsetUnset($session);
        }
    }

    public static function restoreAll(): void
    {
        if (self::$sessions === null) {
            return;
        }

        $sessions = [];
        foreach (self::$sessions as $session) {
            $sessions[] = $session;
        }

        foreach (array_reverse($sessions) as $session) {
            $session->close();
        }
    }

    public static function activeCount(): int
    {
        return self::$sessions === null ? 0 : count(self::$sessions);
    }
}
