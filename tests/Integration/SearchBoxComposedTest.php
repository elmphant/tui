<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Integration;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Runtime\FullScreenSurface;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SplQueue;

final class SearchBoxComposedTest extends TestCase
{
    #[Test]
    public function childCommandsAreScopedBeforeTheParentReceivesThem(): void
    {
        $recorder = new SearchRecorder();
        $model = new ComposedSearchModel(
            SearchBox::new(),
            $recorder,
        );
        $program = Program::new($model);
        $queue = new SplQueue();
        $queue->enqueue(new KeyMsg('t'));
        $queue->enqueue(new KeyMsg('e'));
        $queue->enqueue(new KeyMsg('a'));
        $queue->enqueue(new KeyMsg(Key::Enter));

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('surface')->setValue($program, new FullScreenSurface(new VirtualTerminal(80, 24), new Screen(new VirtualTerminal(80, 24)), false));

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(1, $recorder->submissions);
        self::assertSame('tea', $recorder->query);
        self::assertInstanceOf(FromSearchBox::class, $recorder->received);
        self::assertInstanceOf(SearchSubmitted::class, $recorder->received->inner);
    }
}

final readonly class SearchSubmitted implements Message
{
    public function __construct(public string $query)
    {
    }
}

final readonly class FromSearchBox implements Message
{
    public function __construct(public Message $inner)
    {
    }
}

final class SearchRecorder
{
    public int $submissions = 0;
    public ?string $query = null;
    public ?FromSearchBox $received = null;
}

final readonly class ComposedSearchModel implements Model
{
    public function __construct(
        public SearchBox $search,
        private SearchRecorder $recorder,
    ) {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof FromSearchBox && $message->inner instanceof SearchSubmitted) {
            ++$this->recorder->submissions;
            $this->recorder->query = $message->inner->query;
            $this->recorder->received = $message;

            return UpdateResult::ignored($this);
        }

        if (!$message instanceof KeyMsg) {
            return UpdateResult::ignored($this);
        }

        $childResult = $this->search->update($message, $area);
        self::assertSearchBox($childResult->model);
        $command = $childResult->command?->map(
            static fn (Message $childMessage): Message => new FromSearchBox($childMessage),
        );

        if ($childResult->model === $this->search && $command === null) {
            return UpdateResult::ignored($this);
        }

        return new UpdateResult(new self($childResult->model, $this->recorder), $command);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->search->render($frame, Rect::fromSize($frame->width, $frame->height), $pass->context);
    }

    /**
     * @phpstan-assert SearchBox $model
     */
    private static function assertSearchBox(mixed $model): void
    {
        if (!$model instanceof SearchBox) {
            throw new LogicException('SearchBox update returned a different model type');
        }
    }
}

final readonly class SearchBox implements Focusable
{
    private function __construct(private TextInput $input)
    {
    }

    public static function new(): self
    {
        return new self(TextInput::new()->withPlaceholder('Search'));
    }

    public function sizing(): Sizing
    {
        return Sizing::of($this->input->sizing()->rows, $this->input->sizing()->rows);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === Key::Enter) {
            return new UpdateResult($this, Cmd::message(new SearchSubmitted($this->input->value())));
        }

        $result = $this->input->update($message, $area);
        if ($result->model === $this->input) {
            return $result->command === null
                ? UpdateResult::ignored($this)
                : new UpdateResult($this, $result->command);
        }
        if (!$result->model instanceof TextInput) {
            throw new LogicException('TextInput update returned a different model type');
        }

        return new UpdateResult(new self($result->model), $result->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->input->render($frame, $area, $context);
    }
}
