<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AButtonInALayoutInAPanelTest extends TestCase
{
    #[Test]
    public function aClickPressesTheButtonAndMovesTheKeyboardThere(): void
    {
        $harness = ModelHarness::for(UpdatingApp::inAPanel(Layout::row(self::button(), Text::new(''))), 40, 10)->init();

        $harness->click('Update now');

        self::assertSame('pressed requested', self::heardBy($harness));
        self::assertSame('pane/1/0', $harness->focused()?->value);
    }

    #[Test]
    public function aClickPressesAButtonTwoLayoutsDeep(): void
    {
        $harness = ModelHarness::for(UpdatingApp::inAPanel(Layout::row(Layout::column(self::button()), Text::new(''))), 40, 10)->init();

        $harness->click('Update now');

        self::assertSame('pressed requested', self::heardBy($harness));
        self::assertSame('pane/1/0/0', $harness->focused()?->value);
    }

    #[Test]
    public function aClickPressesTheButtonWhileTextInThePaneIsMarked(): void
    {
        $harness = ModelHarness::for(UpdatingApp::inAPanel(Layout::row(self::button(), Text::new(''))), 40, 10)->init();
        $harness->drag(2, 1, 8, 1);
        $model = $harness->model();
        self::assertInstanceOf(UpdatingApp::class, $model);
        self::assertInstanceOf(Panel::class, $model->pane);
        self::assertNotNull($model->pane->marked(), 'precondition: text is marked');

        $harness->click('Update now');

        self::assertSame('pressed requested', self::heardBy($harness));
        self::assertSame('pane/1/0', $harness->focused()?->value);
    }

    #[Test]
    public function aClickPressesTheButtonInALayoutInADialog(): void
    {
        $harness = ModelHarness::for(new UpdatingApp(Modal::new('Update', Layout::row(self::button(), Text::new('')))), 40, 10)->init();

        $harness->click('Update now');

        self::assertSame('pressed requested', self::heardBy($harness));
        self::assertSame('pane/0/0', $harness->focused()?->value);
    }

    private static function button(): Button
    {
        return Button::new('Update now', new TestMessage('update'));
    }

    /**
     * @param ModelHarness<UpdatingApp> $harness
     */
    private static function heardBy(ModelHarness $harness): string
    {
        $model = $harness->model();
        self::assertInstanceOf(UpdatingApp::class, $model);

        return trim($model->heard);
    }
}

final readonly class UpdatingApp extends App
{
    public function __construct(public Panel|Modal $pane, public string $heard = '')
    {
    }

    public static function inAPanel(Widget $row): self
    {
        return new self(Panel::new('status')->withChildren([Text::new('Ready to update'), $row]));
    }

    public function view(): Widget
    {
        return $this->bind('pane')->on(Pressed::class, static fn (self $app): self => new self($app->pane, $app->heard . 'pressed '));
    }

    protected function onMessage(Message $message, InputContext $input): UpdateResult
    {
        return new UpdateResult($message instanceof TestMessage ? new self($this->pane, $this->heard . 'requested ') : $this);
    }
}
