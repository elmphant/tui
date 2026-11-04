<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplacementCommandTest extends TestCase
{
    #[Test]
    public function writingAComponentBackCanAskForWorkToo(): void
    {
        $harness = ModelHarness::for(new QueryWritingApp(), 40, 3)->init()->type('a');

        self::assertSame(['a'], $harness->model()->searchedFor);
    }
}

final readonly class QueryWritten implements Message
{
    public function __construct(public string $query)
    {
    }
}

final readonly class QueryWritingApp extends App
{
    use Cloneable;

    /**
     * @param list<string> $searchedFor
     */
    public function __construct(public ?TextInput $query = null, public array $searchedFor = [])
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'query',
            $this->query ?? TextInput::new(),
            static fn (self $app, TextInput $query): UpdateResult => new UpdateResult(
                $app->cloneWith(query: $query),
                Cmd::message(new QueryWritten($query->value())),
            ),
        );
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $message instanceof QueryWritten
            ? new UpdateResult($this->cloneWith(searchedFor: [...$this->searchedFor, $message->query]))
            : new UpdateResult($this);
    }
}
