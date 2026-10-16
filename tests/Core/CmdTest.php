<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\CallCmd;
use Elmphant\Tui\Core\Cmd\ClipboardCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Tests\Support\TestMessage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CmdTest extends TestCase
{
    #[Test]
    public function quitReturnsCmd(): void
    {
        $command = Cmd::quit();
        self::assertInstanceOf(Cmd::class, $command);
        self::assertInstanceOf(QuitCmd::class, $command);
    }

    #[Test]
    public function everyBuiltInEffectAnswersToTheNameItWasStartedUnder(): void
    {
        $id = EffectId::named('work');
        [$readEnd, $writeEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $commands = [
            Cmd::tick(1.0, $id),
            Cmd::after(1.0, static fn (): ?Cmd => null, $id),
            Cmd::listen($readEnd, static fn (): ?Cmd => null, $id),
            Cmd::exec(['true'], $id),
            Cmd::process(['true'], $id),
            Cmd::http('https://example.test/', id: $id),
        ];

        foreach ($commands as $command) {
            self::assertInstanceOf(NamesItsEffect::class, $command, $command::class);
            self::assertSame($id, $command->effectId(), $command::class);
        }
        fclose($writeEnd);
    }

    #[Test]
    public function copyCarriesTheTextTheTerminalIsToPutOnTheClipboard(): void
    {
        $command = Cmd::copy('one two');

        self::assertInstanceOf(ClipboardCmd::class, $command);
        self::assertSame('one two', $command->text);
    }

    #[Test]
    public function tickReturnsCmdWithInterval(): void
    {
        $command = Cmd::tick(0.05);
        self::assertInstanceOf(TickCmd::class, $command);
        self::assertSame(0.05, $command->interval);
    }

    #[Test]
    public function batchWrapsMultipleCommands(): void
    {
        $command = Cmd::batch(Cmd::quit(), Cmd::tick(0.1));
        self::assertInstanceOf(BatchCmd::class, $command);
        self::assertCount(2, $command->commands);
    }

    #[Test]
    public function batchNormalizesNamedVariadicArguments(): void
    {
        $first = Cmd::quit();
        $second = Cmd::tick(0.1);

        $command = Cmd::batch(first: $first, second: $second);

        self::assertInstanceOf(BatchCmd::class, $command);
        self::assertSame([0, 1], array_keys($command->commands));
        self::assertSame([$first, $second], $command->commands);
    }

    #[Test]
    public function callWrapsCallback(): void
    {
        $callback = fn () => new TestMessage('done');

        $command = Cmd::call($callback);

        self::assertInstanceOf(CallCmd::class, $command);
        self::assertSame($callback, $command->callback);
    }

    #[Test]
    public function anEffectIsNamedWhereItIsStartedLikeATickerOrATimer(): void
    {
        $load = EffectId::named('load');

        self::assertSame($load, Cmd::exec(['ls'], id: $load)->id);
        self::assertSame($load, Cmd::process(['tail', '-f', 'log'], id: $load)->id);
        self::assertSame($load, Cmd::http('https://example.test', id: $load)->id);
    }

    #[Test]
    public function execHasTypedConfigurationBuilders(): void
    {
        $id = EffectId::named('install');
        $command = Cmd::exec(['composer', 'install'])
            ->withCommand(['composer', 'update'])
            ->withId($id)
            ->withWorkingDirectory('/app')
            ->withTimeout(120.0)
            ->withEnv(['APP_ENV' => 'production'])
            ->withInput("yes\n")
            ->withMaxOutputBytes(1_024);

        self::assertInstanceOf(ExecCmd::class, $command);
        self::assertSame(['composer', 'update'], $command->command);
        self::assertSame($id, $command->id);
        self::assertSame('/app', $command->workingDirectory);
        self::assertSame(120.0, $command->timeout);
        self::assertSame(['APP_ENV' => 'production'], $command->env);
        self::assertSame("yes\n", $command->input);
        self::assertSame(1_024, $command->maxOutputBytes);

        self::assertSame($command, $command->withCommand($command->command));
        self::assertSame($command, $command->withId($command->id));
        self::assertSame($command, $command->withWorkingDirectory($command->workingDirectory));
        self::assertSame($command, $command->withTimeout($command->timeout));
        self::assertSame($command, $command->withEnv($command->env));
        self::assertSame($command, $command->withInput($command->input));
        self::assertSame($command, $command->withMaxOutputBytes($command->maxOutputBytes));
    }

    #[Test]
    public function execUsesTheDocumentedDefaultLimitAndAcceptsOneByte(): void
    {
        $command = Cmd::exec(['true']);

        self::assertSame(8_388_608, $command->maxOutputBytes);
        self::assertSame(1, $command->withMaxOutputBytes(1)->maxOutputBytes);
    }

    #[Test]
    public function processHasTypedConfigurationBuilders(): void
    {
        $id = EffectId::named('watch');
        $command = Cmd::process(['npm', 'run', 'watch'])
            ->withCommand(['npm', 'run', 'dev'])
            ->withId($id)
            ->withWorkingDirectory('/app')
            ->withTimeout(60.0)
            ->withEnv(['NODE_ENV' => 'development'])
            ->withInput("start\n")
            ->withOutputChunkBytes(2_048);

        self::assertInstanceOf(ProcessCmd::class, $command);
        self::assertSame(['npm', 'run', 'dev'], $command->command);
        self::assertSame($id, $command->id);
        self::assertSame('/app', $command->workingDirectory);
        self::assertSame(60.0, $command->timeout);
        self::assertSame(['NODE_ENV' => 'development'], $command->env);
        self::assertSame("start\n", $command->input);
        self::assertSame(2_048, $command->outputChunkBytes);

        self::assertSame($command, $command->withCommand($command->command));
        self::assertSame($command, $command->withId($command->id));
        self::assertSame($command, $command->withWorkingDirectory($command->workingDirectory));
        self::assertSame($command, $command->withTimeout($command->timeout));
        self::assertSame($command, $command->withEnv($command->env));
        self::assertSame($command, $command->withInput($command->input));
        self::assertSame($command, $command->withOutputChunkBytes($command->outputChunkBytes));
    }

    #[Test]
    public function processUsesTheDocumentedDefaultChunkSizeAndAcceptsOneByte(): void
    {
        $command = Cmd::process(['true']);

        self::assertSame(65_536, $command->outputChunkBytes);
        self::assertSame(1, $command->withOutputChunkBytes(1)->outputChunkBytes);
    }

    #[Test]
    public function httpHasTypedConfigurationBuilders(): void
    {
        $id = EffectId::named('download');
        $command = Cmd::http('https://example.com')
            ->withUrl('https://example.com/archive')
            ->withMethod('POST')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('{"ok":true}')
            ->withId($id)
            ->withOptions(['max_redirects' => 2])
            ->withMaxBodyBytes(4_096);

        self::assertInstanceOf(HttpCmd::class, $command);
        self::assertSame('https://example.com/archive', $command->url);
        self::assertSame('POST', $command->method);
        self::assertSame(['Content-Type' => 'application/json'], $command->headers);
        self::assertSame('{"ok":true}', $command->body);
        self::assertSame($id, $command->id);
        self::assertSame(['max_redirects' => 2], $command->options);
        self::assertSame(4_096, $command->maxBodyBytes);

        self::assertSame($command, $command->withUrl($command->url));
        self::assertSame($command, $command->withMethod($command->method));
        self::assertSame($command, $command->withHeaders($command->headers));
        self::assertSame($command, $command->withBody($command->body));
        self::assertSame($command, $command->withId($command->id));
        self::assertSame($command, $command->withOptions($command->options));
        self::assertSame($command, $command->withMaxBodyBytes($command->maxBodyBytes));
    }

    #[Test]
    public function httpUsesTheDocumentedDefaultBodyLimitAndAcceptsOneByte(): void
    {
        $command = Cmd::http('https://example.com');

        self::assertSame(8_388_608, $command->maxBodyBytes);
        self::assertSame(1, $command->withMaxBodyBytes(1)->maxBodyBytes);
    }

    #[Test]
    public function httpRejectsANonPositiveBodyLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HttpCmd maxBodyBytes must be positive');

        Cmd::http('https://example.com')->withMaxBodyBytes(0);
    }

    /**
     * `NAN <= 0` is false, so a not-a-number interval walked past the positivity
     * guard — and every later comparison against it is false too, so the ticker
     * reads as due on every pass of the loop and floods the queue for good.
     */
    #[Test]
    public function aTickerIntervalThatIsNotANumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cmd::tick(NAN);
    }

    /**
     * A delay had no guard at all, so a negative one fired on the next pass as
     * if it had been asked for, and a not-a-number one was a timer whose due
     * time nothing could compare.
     */
    #[Test]
    public function aDelayThatNamesNoMomentIsRefused(): void
    {
        $refused = 0;

        foreach ([NAN, INF, -1.0] as $delay) {
            try {
                Cmd::after($delay, static fn () => null);
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }

        self::assertSame(3, $refused);
    }

    #[Test]
    public function updateResultDefaults(): void
    {
        $model = $this->createStub(Model::class);
        $result = new UpdateResult($model);
        self::assertSame($model, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function updateResultWithCommand(): void
    {
        $model = $this->createStub(Model::class);
        $result = new UpdateResult($model, Cmd::quit());
        self::assertInstanceOf(Cmd::class, $result->command);
    }
}
