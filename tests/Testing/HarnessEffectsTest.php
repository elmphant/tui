<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\HttpResultMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Testing\ModelHarness;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessEffectsTest extends TestCase
{
    #[Test]
    public function whatAModelAskedForIsFoundInsideItsBatchesMapsAndScopes(): void
    {
        $harness = ModelHarness::for(new EffectAsker())->press('l');

        $execs = $harness->issued(ExecCmd::class);

        self::assertCount(1, $execs);
        self::assertSame(['ls'], $execs[0]->command);
        self::assertSame([], $harness->press('x')->issued(ExecCmd::class), 'only what the last action asked for');
    }

    #[Test]
    public function anAnswerArrivesAsTheRuntimeDeliversIt(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('l')->answer('load', stdout: 'a b')->model();

        $answer = $model->lastOf(ChildSaid::class)->message;
        self::assertInstanceOf(ProcessResultMsg::class, $answer);
        self::assertSame('a b', $answer->stdout);
        self::assertTrue($answer->successful);
        self::assertTrue($answer->isFrom('load'));
        self::assertEquals(EffectId::named('load')->within('child'), $answer->effectId, 'in the scope it ran in');
    }

    #[Test]
    public function aFailedRunIsAnsweredWithItsExitCodeAndErrorOutput(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('l')->answer('load', exitCode: 2, stderr: 'boom')->model();

        $answer = $model->lastOf(ChildSaid::class)->message;
        self::assertInstanceOf(ProcessResultMsg::class, $answer);
        self::assertSame(2, $answer->exitCode);
        self::assertFalse($answer->successful);
        self::assertSame('boom', $answer->stderr);
    }

    #[Test]
    public function aStreamIsFedOutputAndThenFinished(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('s')->stream('tail', "one\n")->finish('tail', exitCode: 0)->model();

        [$output, $finished] = array_map(
            static fn (Message $said): Message => $said instanceof ChildSaid ? $said->message : $said,
            \array_slice($model->received, -2),
        );
        self::assertInstanceOf(ProcessOutputMsg::class, $output);
        self::assertSame("one\n", $output->output);
        self::assertInstanceOf(ProcessFinishedMsg::class, $finished);
        self::assertTrue($finished->successful);
    }

    #[Test]
    public function aFailureArrivesAsAnErrorFromThatEffect(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('l')->fail('load', 'timed out')->model();

        $error = $model->lastOf(ChildSaid::class)->message;
        self::assertInstanceOf(ErrorMsg::class, $error);
        self::assertSame('timed out', $error->error->getMessage());
        self::assertTrue($error->isFrom('load'));
    }

    #[Test]
    public function aRequestIsAnsweredWithItsResponse(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('h')->respond('page', 404, 'gone')->model();

        $response = $model->lastOf(ChildSaid::class)->message;
        self::assertInstanceOf(HttpResultMsg::class, $response);
        self::assertSame(404, $response->statusCode);
        self::assertSame('gone', $response->body);
    }

    #[Test]
    public function aNameRunningInTwoScopesMustBeNamedWithItsScope(): void
    {
        $harness = ModelHarness::for(new EffectAsker())->press('l', 'o');

        $harness->answer(EffectId::named('load')->within('other'), stdout: 'theirs');

        try {
            $harness->press('o')->answer('load');
            self::fail('a bare name cannot say which of the two it means');
        } catch (LogicException $ambiguous) {
            self::assertStringContainsString('more than one scope', $ambiguous->getMessage());
        }
    }

    #[Test]
    public function aRunningTickerTicksWhenTheTestSaysSoAndKeepsRunning(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('t')->tick('clock', at: 12.5)->tick('clock')->model();

        $ticks = array_values(array_filter(
            array_map(static fn (Message $said): Message => $said instanceof ChildSaid ? $said->message : $said, $model->received),
            static fn (Message $message): bool => $message instanceof TickMsg,
        ));
        self::assertCount(2, $ticks);
        self::assertSame(12.5, $ticks[0]->timestamp);
        self::assertTrue($ticks[0]->isFrom('clock'));
    }

    #[Test]
    public function anEffectOfTheApplicationsOwnSaysWhatItProducesAndKeepsRunning(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('u')->emit('feed', new Beat(1))->emit('feed', new Beat(2))->model();

        $beats = array_values(array_filter($model->received, static fn (Message $message): bool => $message instanceof ChildSaid && $message->message instanceof Beat));
        self::assertEquals([new ChildSaid(new Beat(1)), new ChildSaid(new Beat(2))], $beats);
    }

    #[Test]
    public function aCallIsRunWhenTheTestSaysSoAndItsMessageArrivesThroughItsMaps(): void
    {
        $harness = ModelHarness::for(new EffectAsker())->press('k');
        self::assertSame([], array_filter($harness->model()->received, static fn (Message $message): bool => $message instanceof ChildSaid));

        self::assertEquals(new ChildSaid(new Beat(7)), $harness->runCalls()->model()->lastOf(ChildSaid::class));
    }

    #[Test]
    public function aCommandACallAnswersWithRunsInTheScopeOfTheCall(): void
    {
        $model = ModelHarness::for(new EffectAsker())->press('e')->runCalls()->answer(EffectId::named('load')->within('child'), stdout: 'later')->model();

        $answer = $model->lastOf(ChildSaid::class)->message;
        self::assertInstanceOf(ProcessResultMsg::class, $answer);
        self::assertSame('later', $answer->stdout);
    }

    #[Test]
    public function anEffectTheModelNeverStartedCannotBeAnswered(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No effect named "save" is running');

        ModelHarness::for(new EffectAsker())->press('l')->answer('save');
    }

    #[Test]
    public function anAnsweredRunIsOver(): void
    {
        $harness = ModelHarness::for(new EffectAsker())->press('l')->answer('load');

        $this->expectException(LogicException::class);
        $harness->answer('load');
    }

    #[Test]
    public function aCancelledEffectIsOver(): void
    {
        $harness = ModelHarness::for(new EffectAsker())->press('l', 'c');

        $this->expectException(LogicException::class);
        $harness->answer('load');
    }
}

final readonly class Beat implements Message
{
    public function __construct(public int $count)
    {
    }
}

final readonly class FeedCmd extends Cmd implements NamesItsEffect
{
    public function effectId(): EffectId
    {
        return EffectId::named('feed');
    }
}

final readonly class ChildSaid implements Message
{
    public function __construct(public Message $message)
    {
    }
}

final class EffectAsker implements Model
{
    /**
     * @var list<Message>
     */
    public array $received = [];

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->received[] = $message;

        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        if ($message->key === 'o') {
            return new UpdateResult($this, Cmd::exec(['ls'], EffectId::named('load'))->scopedTo('other'));
        }

        $child = match ($message->key) {
            'l' => Cmd::batch(Cmd::exec(['ls'], EffectId::named('load')), Cmd::log('asked')),
            's' => Cmd::process(['tail', '-f', 'log'], EffectId::named('tail')),
            'h' => Cmd::http('https://example.com', id: EffectId::named('page')),
            't' => Cmd::tick(1.0, EffectId::named('clock')),
            'c' => Cmd::cancel(EffectId::named('load')),
            'u' => new FeedCmd(),
            'k' => Cmd::call(static fn (): Message => new Beat(7)),
            'e' => Cmd::call(static fn (): Cmd => Cmd::exec(['ls'], EffectId::named('load'))),
            default => null,
        };

        return new UpdateResult($this, $child?->map(static fn (Message $said): Message => new ChildSaid($said))->scopedTo('child'));
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function lastOf(string $type): Message
    {
        foreach (array_reverse($this->received) as $message) {
            if ($message instanceof $type) {
                return $message;
            }
        }

        throw new LogicException('Nothing of ' . $type . ' arrived');
    }
}
