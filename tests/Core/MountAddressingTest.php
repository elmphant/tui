<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Mount;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sub-model's component speaks for the sub-model, not for the App that hosts it.
 * The App cannot know whose a bare message is once it is out, so the Mount says on
 * the way past.
 */
final class MountAddressingTest extends TestCase
{
    #[Test]
    public function aKeyAnsweredByAMountedComponentComesBackAddressedToItsModel(): void
    {
        $app = new AddressingApp(new TalkativeSubModel());

        $command = $app->update(new KeyMsg(Key::Enter), Rect::fromSize(20, 5), new FocusState(ComponentId::named('speaker')))->command;

        self::assertInstanceOf(MappedCmd::class, $command, 'the address is a wrap the runtime applies to what it emits');
        self::assertInstanceOf(MessageCmd::class, $command->inner);

        $delivered = ($command->wrap)($command->inner->message);

        self::assertInstanceOf(ForTheSubModel::class, $delivered);
        self::assertInstanceOf(Spoke::class, $delivered->inner);
    }
}

final readonly class ForTheSubModel implements Message
{
    public function __construct(public Message $inner)
    {
    }
}

final readonly class Spoke implements Message
{
}

final readonly class AddressingApp extends App
{
    use Cloneable;

    public function __construct(public TalkativeSubModel $child)
    {
    }

    public function view(): Widget
    {
        return Mount::of(
            $this->child,
            $this->child->view(),
            static fn (self $app, TalkativeSubModel $child): self => $app->cloneWith(child: $child),
            static fn (Message $message): Message => new ForTheSubModel($message),
        );
    }
}

final readonly class TalkativeSubModel implements Reducer
{
    use Cloneable;

    public function __construct(public Speaker $speaker = new Speaker())
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Component::bind(
            'speaker',
            $this->speaker,
            static fn (self $model, Speaker $speaker): self => $model->cloneWith(speaker: $speaker),
        );
    }
}

final readonly class Speaker implements Focusable
{
    use Cloneable;

    public function __construct(private bool $focused = true)
    {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::changed($this, Cmd::message(new Spoke()));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
