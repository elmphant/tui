<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Keyboard;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyboardHintsDuringAnUpdateTest extends TestCase
{
    #[Test]
    public function aMouseHookIsToldWhoHoldsTheKeyboardAndTheHintsTheFooterShows(): void
    {
        $harness = ModelHarness::for(new HintsRecordingApp(ListView::new([new SimpleListItem('one')])), 30, 4)->init();

        $harness->clickAt(1, 0);

        self::assertEquals(ComponentId::named('list'), $harness->model()->focused);
        self::assertSame($harness->focusedHints(), $harness->model()->recorded);
        self::assertNotSame([], $harness->model()->recorded);
    }
}

final readonly class HintsRecordingApp extends CopyableTestApp
{
    /**
     * @param array<string, string> $recorded
     */
    public function __construct(public ListView $list, public array $recorded = [], public ?ComponentId $focused = null)
    {
    }

    public function view(): Widget
    {
        return Component::bind('list', $this->list, static fn (self $app, ListView $list): self => new self($list, $app->recorded, $app->focused));
    }

    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return new UpdateResult(new self($this->list, $keyboard->hints(), $keyboard->focused()));
    }
}
