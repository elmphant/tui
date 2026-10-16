<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Focus\FocusGutter;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GutteredChildAnswersOnceTest extends TestCase
{
    #[Test]
    public function aTickReachesAFieldBehindAGutterOnce(): void
    {
        $harness = ModelHarness::for(new GutteredPingerApp(), 30, 6);

        $harness->dispatch(new TickMsg(1.0));

        $model = $harness->model();
        self::assertInstanceOf(GutteredPingerApp::class, $model);
        self::assertSame(1, $model->pings);
    }
}

final readonly class Pinged implements Message
{
}

final readonly class Pinger implements Focusable
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $message instanceof TickMsg ? Cmd::message(new Pinged()) : null);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class GutteredPingerApp extends App
{
    public function __construct(public int $pings = 0)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'form',
            Panel::new('Form')->add(FocusGutter::around(new Pinger())),
            static fn (self $app, Panel $form): self => $app,
        );
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $message instanceof Pinged ? UpdateResult::changed(new self($this->pings + 1)) : UpdateResult::keep($this);
    }
}
