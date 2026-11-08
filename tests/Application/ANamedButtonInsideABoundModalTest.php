<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\Dismissed;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ANamedButtonInsideABoundModalTest extends TestCase
{
    #[Test]
    public function theKeyboardSentToItByNameLandsOnIt(): void
    {
        $harness = ModelHarness::for(NamedCancelApp::asking(), width: 50, height: 12)->init();

        self::assertSame('cancel', $harness->focused()?->value);
    }

    #[Test]
    public function escapeOnItClosesTheModal(): void
    {
        $harness = ModelHarness::for(NamedCancelApp::asking(), width: 50, height: 12)->init();

        $harness->press('escape');

        self::assertNull($harness->model()->dialog);
    }

    #[Test]
    public function enterOnItSendsTheMessageOfTheButton(): void
    {
        $harness = ModelHarness::for(NamedCancelApp::asking(), width: 50, height: 12)->init();

        $harness->press('enter');

        self::assertSame(['cancelled'], $harness->model()->heard);
    }

    #[Test]
    public function itKeepsItsNameOnceItsAnswerIsStored(): void
    {
        $harness = ModelHarness::for(NamedCancelApp::asking(), width: 50, height: 12)->init();

        $harness->press('enter', 'enter');

        self::assertSame('cancel', $harness->focused()?->value);
        self::assertSame(['cancelled', 'cancelled'], $harness->model()->heard);
    }

    #[Test]
    public function tabStopsAtItOnceUnderItsName(): void
    {
        $harness = ModelHarness::for(NamedCancelApp::asking(), width: 50, height: 12)->init();
        $visited = [];

        for ($step = 0; $step < 4; ++$step) {
            $harness->press('tab');
            $visited[] = $harness->focused()?->value;
        }

        self::assertSame(['dialog/0/1/1', 'cancel', 'dialog/0/1/1', 'cancel'], $visited);
    }
}

final readonly class NamedCancelPressed implements Message
{
}

final readonly class NamedCancelApp extends App
{
    /**
     * @param list<string> $heard
     */
    public function __construct(public ?Modal $dialog = null, public array $heard = [])
    {
    }

    public static function asking(): self
    {
        return new self(Modal::new('Sure?', Layout::column(
            Text::new('Really?'),
            Layout::row(
                Component::named('cancel', Button::new('Cancel', new NamedCancelPressed())),
                Button::new('Run'),
            ),
        )));
    }

    public function view(): Widget
    {
        if ($this->dialog === null) {
            return Text::new('closed');
        }

        return Overlay::new(
            Text::new('background'),
            $this->bind('dialog')->on(Dismissed::class, static fn (self $app): self => new self(null, $app->heard)),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focus('cancel');
    }

    protected function onMessage(Message $message, InputContext $input): self
    {
        return $message instanceof NamedCancelPressed ? new self($this->dialog, [...$this->heard, 'cancelled']) : $this;
    }
}
