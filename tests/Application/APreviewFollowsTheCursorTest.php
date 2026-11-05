<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APreviewFollowsTheCursorTest extends TestCase
{
    #[Test]
    public function movingTheCursorReachesOnComponentsChanged(): void
    {
        $harness = ModelHarness::for(new PreviewingApp(ListView::fromStrings(['notes.txt', 'todo.txt'])), 40, 6)->init();

        $harness->press('down');

        self::assertStringContainsString('preview of todo.txt', $harness->screen()->plainText());
    }
}

final readonly class PreviewingApp extends App
{
    public function __construct(public ListView $files, public string $previewed = '')
    {
    }

    public function view(): Widget
    {
        return Layout::column($this->bind('files'), Text::new("preview of {$this->previewed}"));
    }

    /**
     * @param list<ComponentId> $ids
     */
    protected function onComponentsChanged(array $ids): self
    {
        return new self($this->files, $this->files->cursorItem()?->title() ?? '');
    }
}
