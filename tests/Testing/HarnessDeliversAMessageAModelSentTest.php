<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deliver self-messages through update() so the harness follows the same path as the runtime.
 */
final class HarnessDeliversAMessageAModelSentTest extends TestCase
{
    #[Test]
    public function aMessageAnUpdateSentComesBackThroughUpdate(): void
    {
        $driven = ModelHarness::for(new SpeaksToItselfApp(), 20, 2)->init();

        $driven->dispatch(new KeyMsg('r'));

        self::assertSame(['r', 'the-errand'], $driven->model()->seen);
    }

    #[Test]
    public function aMessageSentAlongsideOtherEffectsArrivesToo(): void
    {
        $driven = ModelHarness::for(new SpeaksToItselfApp(), 20, 2)->init();

        $driven->dispatch(new KeyMsg('b'));

        self::assertSame(
            ['b', 'the-errand'],
            $driven->model()->seen,
            'a batch is how an update says two things at once',
        );
    }

    #[Test]
    public function messagesArriveInTheOrderTheyWereSent(): void
    {
        $driven = ModelHarness::for(new SpeaksToItselfApp(), 20, 2)->init();

        $driven->dispatch(new KeyMsg('o'));

        self::assertSame(['o', 'A', 'B', 'C'], $driven->model()->seen);
    }

    #[Test]
    public function nothingArrivesAfterAQuit(): void
    {
        $driven = ModelHarness::for(new SpeaksToItselfApp(), 20, 2)->init();

        $driven->dispatch(new KeyMsg('q'));

        self::assertSame(['q'], $driven->model()->seen);
    }
}

final readonly class TheErrand implements Message
{
}

final readonly class Said implements Message
{
    public function __construct(public string $word)
    {
    }
}

final readonly class SpeaksToItselfApp extends App
{
    /**
     * @param list<string> $seen
     */
    public function __construct(public array $seen = [])
    {
    }

    public function view(): Widget
    {
        return Text::new('nothing to press');
    }

    protected function onKey(KeyMsg $message, InputContext $input): UpdateResult
    {
        $errand = Cmd::message(new TheErrand());

        return new UpdateResult(
            $this->cloneWith(seen: [...$this->seen, $message->key]),
            match ($message->key) {
                'b' => Cmd::batch(Cmd::focusNowhere(), $errand),
                'o' => Cmd::batch(Cmd::message(new Said('A')), Cmd::message(new Said('B'))),
                'q' => Cmd::batch(Cmd::message(new Said('A')), Cmd::quit(), Cmd::message(new Said('B'))),
                default => $errand,
            },
        );
    }

    protected function onMessage(Message $message, InputContext $input): UpdateResult
    {
        return match (true) {
            $message instanceof TheErrand => new UpdateResult($this->cloneWith(seen: [...$this->seen, 'the-errand'])),
            $message instanceof Said => new UpdateResult(
                $this->cloneWith(seen: [...$this->seen, $message->word]),
                $message->word === 'A' ? Cmd::message(new Said('C')) : null,
            ),
            default => new UpdateResult($this),
        };
    }
}
