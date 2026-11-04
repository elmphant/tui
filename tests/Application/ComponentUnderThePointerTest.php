<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentUnderThePointerTest extends TestCase
{
    #[Test]
    public function onMouseCanAskWhichComponentIsUnderThePress(): void
    {
        $harness = ModelHarness::for(PointerAskingApp::start(), 40, 6)->click('Pay rent');

        self::assertSame(['done'], self::pressedOn($harness));
    }

    #[Test]
    public function aPressOnNoComponentAnswersNothing(): void
    {
        $harness = ModelHarness::for(PointerAskingApp::start(), 40, 6)->click('Board');

        self::assertSame([null], self::pressedOn($harness));
    }

    /**
     * @return list<?string>
     */
    private static function pressedOn(ModelHarness $harness): array
    {
        $app = $harness->model();
        self::assertInstanceOf(PointerAskingApp::class, $app);

        return $app->pressedOn;
    }
}

final readonly class PointerAskingApp extends App
{
    /**
     * @param list<?string> $pressedOn
     */
    public function __construct(private ListView $todo, private ListView $done, public array $pressedOn = [])
    {
    }

    public static function start(): self
    {
        return new self(ListView::fromStrings(['Buy milk']), ListView::fromStrings(['Pay rent']));
    }

    public function view(): Widget
    {
        return Layout::column(
            Text::new('Board'),
            Layout::row($this->bind('todo'), $this->bind('done')),
        );
    }

    protected function onMouse(MouseMsg $message, InputContext $input): self
    {
        return $message->isClick() ? $this->cloneWith(pressedOn: [...$this->pressedOn, $input->componentAt($message)?->value]) : $this;
    }
}
