<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Runtime\ShutdownRestorer;
use Elmphant\Tui\Runtime\TerminalSession;
use Elmphant\Tui\Runtime\TerminalSessionState;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ShutdownRestorerTest extends TestCase
{
    #[Test]
    public function registerKeepsPreviouslyRegisteredSessions(): void
    {
        $baseline = ShutdownRestorer::activeCount();
        $first = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        $second = new TerminalSession(new VirtualTerminal(80, 24), false, false);

        try {
            ShutdownRestorer::register($first);
            ShutdownRestorer::register($second);

            self::assertSame($baseline + 2, ShutdownRestorer::activeCount());
        } finally {
            ShutdownRestorer::unregister($first);
            ShutdownRestorer::unregister($second);
        }

        self::assertSame($baseline, ShutdownRestorer::activeCount());
    }

    #[Test]
    public function registerInstallsTheShutdownHookExactlyOnce(): void
    {
        $hookRegistered = new ReflectionProperty(ShutdownRestorer::class, 'hookRegistered');
        $previousHookRegistered = $hookRegistered->getValue();
        self::assertIsBool($previousHookRegistered);
        $hookRegistered->setValue(null, false);

        /** @var list<Closure(): void> $capturedHooks */
        $capturedHooks = [];
        ShutdownRestorer::overrideHookRegistrar(static function (Closure $hook) use (&$capturedHooks): void {
            $capturedHooks[] = $hook;
        });

        $first = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        $second = new TerminalSession(new VirtualTerminal(80, 24), false, false);

        try {
            ShutdownRestorer::register($first);
            ShutdownRestorer::register($second);

            self::assertCount(1, $capturedHooks);
            self::assertTrue($hookRegistered->getValue());

            $capturedHooks[0]();

            self::assertSame(TerminalSessionState::Closed, $first->state());
            self::assertSame(TerminalSessionState::Closed, $second->state());
        } finally {
            ShutdownRestorer::overrideHookRegistrar(null);
            $hookRegistered->setValue(null, $previousHookRegistered);
            ShutdownRestorer::unregister($first);
            ShutdownRestorer::unregister($second);
        }
    }

    #[Test]
    public function aForkedChildLeavesTheParentsSessionsAlone(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('needs pcntl');
        }

        $session = new TerminalSession(new VirtualTerminal(80, 24), false, false);
        ShutdownRestorer::register($session);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentEnd, $childEnd] = $sockets;

        try {
            $child = pcntl_fork();
            if ($child === 0) {
                ShutdownRestorer::restoreAll();
                fwrite($childEnd, $session->state()->name);
                posix_kill(posix_getpid(), SIGKILL);
            }

            pcntl_waitpid($child, $status);
            fclose($childEnd);

            self::assertSame(TerminalSessionState::New->name, stream_get_contents($parentEnd));
        } finally {
            ShutdownRestorer::unregister($session);
        }
    }
}
