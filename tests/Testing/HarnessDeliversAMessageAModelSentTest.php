<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A widget asks its host for something by sending a message, which the runtime
 * puts back through update. A harness that dropped it would let a test pass on
 * a path the real loop never takes.
 */
final class HarnessDeliversAMessageAModelSentTest extends TestCase
{
    #[Test]
    public function aMessageAnUpdateSentComesBackThroughUpdate(): void
    {
        $driven = ModelHarness::for(new SpeaksToItselfApp(), 20, 2)->initialize();

        $driven->dispatch(new KeyMsg('r'));

        self::assertSame(['r', 'the-errand'], $driven->model()->seen);
    }

    #[Test]
    public function aMessageSentAlongsideOtherEffectsArrivesToo(): void
    {
        $driven = ModelHarness::for(new SpeaksToItselfApp(), 20, 2)->initialize();

        $driven->dispatch(new KeyMsg('b'));

        self::assertSame(
            ['b', 'the-errand'],
            $driven->model()->seen,
            'a batch is how an update says two things at once',
        );
    }
}

final readonly class TheErrand implements Message
{
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

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        $errand = Cmd::message(new TheErrand());

        return new UpdateResult(
            $this->cloneWith(seen: [...$this->seen, $message->key]),
            $message->key === 'b' ? Cmd::batch(Cmd::focusNothing(), $errand) : $errand,
        );
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $message instanceof TheErrand
            ? UpdateResult::changed($this->cloneWith(seen: [...$this->seen, 'the-errand']))
            : UpdateResult::keep($this);
    }
}
