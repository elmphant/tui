<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppHookDispatchTest extends TestCase
{
    #[Test]
    public function aKeyReachesTheSubclassHook(): void
    {
        self::assertSame('key', $this->trailAfter(new KeyMsg('x')));
    }

    #[Test]
    public function aDeclaredActionRunsItsHandler(): void
    {
        self::assertSame('action:save', $this->trailAfter(new KeyMsg('s', ctrl: true)));
    }

    #[Test]
    public function aMouseMessageReachesTheSubclassHook(): void
    {
        self::assertSame('mouse', $this->trailAfter(new MouseMsg(1, 1, MouseAction::Press, MouseButton::Left)));
    }

    #[Test]
    public function aTickReachesTheSubclassHook(): void
    {
        self::assertSame('tick', $this->trailAfter(new TickMsg(0.0)));
    }

    #[Test]
    public function aResizeReachesTheSubclassHook(): void
    {
        self::assertSame('resize', $this->trailAfter(new ResizeMsg(30, 10)));
    }

    #[Test]
    public function anUnclaimedMessageReachesTheSubclassHook(): void
    {
        self::assertSame('message', $this->trailAfter(new HookProbeMsg()));
    }

    #[Test]
    public function theSubclassSetupRunsOnInit(): void
    {
        self::assertNotNull((new HookRecordingApp())->init(), 'setup() supplied the initial command');
    }

    #[Test]
    public function everyEventHookLearnsHowBigTheScreenIs(): void
    {
        $app = new HookRecordingApp();
        $screen = new Rect(0, 0, 37, 11);

        foreach ([new KeyMsg('x'), new HookProbeMsg(), new TickMsg(1.0)] as $message) {
            $model = $app->update($message, $screen)->model;
            self::assertInstanceOf(HookRecordingApp::class, $model);
            self::assertSame('37x11', $model->screen, $message::class);
        }
    }

    private function trailAfter(Message $message): string
    {
        $result = (new HookRecordingApp())->update($message, new Rect(0, 0, 20, 5));
        $model = $result->model;
        self::assertInstanceOf(HookRecordingApp::class, $model);

        return $model->trail;
    }
}

final class HookProbeMsg implements Message
{
}

final readonly class HookRecordingApp extends App
{
    public function __construct(
        public string $trail = '',
        public string $screen = '',
    ) {
    }

    public function view(): Widget
    {
        return Text::new($this->trail);
    }

    protected function setup(): ?Cmd
    {
        return Cmd::tick(1.0);
    }

    protected function actions(): AppActions
    {
        return AppActions::new()->with('save', ['ctrl+s'], static fn (): self => new self('action:save'));
    }

    protected function onKey(KeyMsg $message, InputContext $input): UpdateResult
    {
        return new UpdateResult(new self('key', self::size($input->screen())));
    }

    protected function onMouse(MouseMsg $message, InputContext $input): UpdateResult
    {
        return new UpdateResult(new self('mouse'));
    }

    protected function onTick(TickMsg $message, InputContext $input): UpdateResult
    {
        return new UpdateResult(new self('tick', self::size($input->screen())));
    }

    protected function onResize(ResizeMsg $message): UpdateResult
    {
        return new UpdateResult(new self('resize'));
    }

    protected function onMessage(Message $message, InputContext $input): UpdateResult
    {
        return new UpdateResult(new self('message', self::size($input->screen())));
    }

    private static function size(Rect $screen): string
    {
        return $screen->width . 'x' . $screen->height;
    }
}
