<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessInputTest extends TestCase
{
    #[Test]
    public function keysArePressedInTheSpellingBindingsUse(): void
    {
        $model = ModelHarness::for(new InputRecorder())->press('tab', 'shift+tab', 'ctrl+r', 'enter')->model();

        self::assertEquals(
            [new KeyMsg(Key::Tab), new KeyMsg(Key::Tab, shift: true), new KeyMsg('r', ctrl: true), new KeyMsg(Key::Enter)],
            $model->keys(),
        );
    }

    #[Test]
    public function textIsTypedOneCharacterAtATime(): void
    {
        $model = ModelHarness::for(new InputRecorder())->type('hé!')->model();

        self::assertEquals([new KeyMsg('h'), new KeyMsg('é'), new KeyMsg('!')], $model->keys());
    }

    #[Test]
    public function aClickOnTextPressesAndLetsGoWhereItIsDrawn(): void
    {
        $model = ModelHarness::for(new InputRecorder(), 20, 3)->click('Run')->model();

        self::assertEquals(
            [[MouseAction::Press, 4, 1], [MouseAction::Release, 4, 1]],
            $model->pointer(),
        );
    }

    #[Test]
    public function aClickLandsAnOffsetAwayFromTheTextItIsAimedBy(): void
    {
        $model = ModelHarness::for(new InputRecorder(), 20, 3)->click('Run', offset: 2)->model();

        self::assertEquals([[MouseAction::Press, 6, 1], [MouseAction::Release, 6, 1]], $model->pointer());
    }

    #[Test]
    public function whatAClickLedToCountsItsPressAsWellAsItsRelease(): void
    {
        $harness = ModelHarness::for(new InputRecorder(answersPresses: true), 20, 3)->click('Run');

        self::assertCount(1, $harness->issued(TickCmd::class), 'the press asked for it, the release did not');
    }

    #[Test]
    public function aClickOnTextThatIsNotOnScreenSaysSo(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"Stop" is not on screen');

        ModelHarness::for(new InputRecorder(), 20, 3)->click('Stop');
    }

    #[Test]
    public function theWheelTurnsOverTextByNotchesDownOrUp(): void
    {
        $model = ModelHarness::for(new InputRecorder(), 20, 3)->wheel('Run', 3)->wheel('Run', -1)->model();

        self::assertEquals(
            [[MouseAction::WheelDown, 4, 1, 3], [MouseAction::WheelUp, 4, 1, 1]],
            $model->wheels(),
        );
    }

    #[Test]
    public function aDragPressesMovesWithTheButtonHeldAndLetsGoWhereItEnds(): void
    {
        $model = ModelHarness::for(new InputRecorder(), 20, 3)->drag(4, 1, 9, 2)->model();

        self::assertEquals(
            [[MouseAction::Press, 4, 1], [MouseAction::Motion, 9, 2], [MouseAction::Release, 9, 2]],
            $model->pointer(),
        );
    }

    #[Test]
    public function aPasteArrivesAsOneMessage(): void
    {
        $model = ModelHarness::for(new InputRecorder())->paste("two\nlines")->model();

        self::assertEquals([new PasteMsg("two\nlines")], $model->pastes());
    }

    #[Test]
    public function theSameInputIsAtHandForATestThatCallsUpdateItself(): void
    {
        self::assertEquals(new KeyMsg(Key::Tab, shift: true), UserInput::key('shift+tab'));
        self::assertEquals([new KeyMsg('o'), new KeyMsg('k')], UserInput::typed('ok'));
        self::assertEquals(new MouseMsg(4, 1, MouseAction::Press, MouseButton::Left), UserInput::press(4, 1));
        self::assertEquals(new MouseMsg(9, 2, MouseAction::Motion, MouseButton::Left), UserInput::drag(9, 2));
        self::assertEquals(new PasteMsg('text'), UserInput::paste('text'));
    }

    #[Test]
    public function typingIntoAFieldNobodyBoundSaysSo(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Typed "abc", but no component holds the keyboard. The TextInput on screen is not bound, so it never gets keys: wrap it in Component::bind()');

        ModelHarness::for(new ForgetsToBindItsField(TextInput::new()), 20, 2)->type('abc');
    }
}

final readonly class ForgetsToBindItsField extends App
{
    public function __construct(private TextInput $name)
    {
    }

    public function view(): Widget
    {
        return Layout::column(Text::new('Name'), $this->name);
    }
}

final class InputRecorder implements Model
{
    /**
     * @var list<Message>
     */
    private array $received = [];

    public function __construct(private readonly bool $answersPresses = false)
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->received[] = $message;
        $pressed = $message instanceof MouseMsg && $message->action === MouseAction::Press;

        return new UpdateResult($this, $this->answersPresses && $pressed ? Cmd::tick(1.0) : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $frame->putString(4, 1, 'Run');
    }

    /**
     * @return list<KeyMsg>
     */
    public function keys(): array
    {
        return array_values(array_map(
            static fn (KeyMsg $key): KeyMsg => new KeyMsg($key->key, $key->ctrl, $key->alt, $key->shift),
            array_filter($this->received, static fn (Message $message): bool => $message instanceof KeyMsg),
        ));
    }

    /**
     * @return list<array{MouseAction, int, int}>
     */
    public function pointer(): array
    {
        return array_map(
            static fn (MouseMsg $mouse): array => [$mouse->action, $mouse->x, $mouse->y],
            array_values(array_filter($this->received, static fn (Message $message): bool => $message instanceof MouseMsg && !$message->isWheel())),
        );
    }

    /**
     * @return list<PasteMsg>
     */
    public function pastes(): array
    {
        return array_values(array_filter($this->received, static fn (Message $message): bool => $message instanceof PasteMsg));
    }

    /**
     * @return list<array{MouseAction, int, int, int}>
     */
    public function wheels(): array
    {
        return array_map(
            static fn (MouseMsg $mouse): array => [$mouse->action, $mouse->x, $mouse->y, $mouse->repeat],
            array_values(array_filter($this->received, static fn (Message $message): bool => $message instanceof MouseMsg && $message->isWheel())),
        );
    }
}
