<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Keyboard;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PressThatOpensAPopupTest extends TestCase
{
    #[Test]
    public function thePopupAPressOpenedDoesNotHearThatPress(): void
    {
        $harness = ModelHarness::for(new PopupOnPressApp(), width: 60, height: 20);
        $harness->init();

        $harness->dispatch(UserInput::press(1, 1));

        self::assertTrue($harness->model()->open, 'the press outside the box it opened is not a dismissal');
    }

    #[Test]
    public function aLaterPressOutsideThePopupStillClosesIt(): void
    {
        $harness = ModelHarness::for(new PopupOnPressApp(), width: 60, height: 20);
        $harness->init();

        $harness->dispatch(UserInput::press(1, 1));
        $harness->dispatch(UserInput::press(1, 1));

        self::assertFalse($harness->model()->open);
    }
}

final readonly class PopupOnPressApp extends App
{
    public function __construct(public bool $open = false)
    {
    }

    public function view(): Widget
    {
        $base = Text::new('base');

        return $this->open
            ? Overlay::new($base, Component::bind('popup', Modal::new('popup', Text::new('inside'))->withWidthPercent(30)->withHeightPercent(30), static fn (self $app, Modal $popup): self => new self($popup->isOpen())))
            : $base;
    }

    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return $this->open || !$message->isClick() ? new UpdateResult($this) : new UpdateResult(new self(true));
    }
}
