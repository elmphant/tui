<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramFocusPaintTest extends TestCase
{
    #[Test]
    public function whereTheRuntimePutsTheKeyboardIsWhereTheCaretIsDrawn(): void
    {
        self::assertSame(0, self::caretRowWithFocusOn('first'));
        self::assertSame(1, self::caretRowWithFocusOn('second'));
    }

    private static function caretRowWithFocusOn(string $target): ?int
    {
        $frame = new Frame(40, 6);
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(FocusState::on(ComponentId::named($target)));

        (new FocusPaintApp(TextInput::new(), TextInput::new()))->render($frame, $pass);

        return $frame->cursorPosition()?->y;
    }
}

final readonly class FocusPaintApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }
}
