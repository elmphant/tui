<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use ArrayObject;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentFocusScopeTest extends TestCase
{
    #[Test]
    public function onlyTheFocusedComponentsSubtreeIsToldItHasTheKeyboard(): void
    {
        /** @var ArrayObject<string, bool> $seen */
        $seen = new ArrayObject();

        $tree = Layout::vertical(
            Component::bind('first', new FocusProbe('first', $seen), static fn (object $app, Interactive $w): object => $app),
            Component::bind('second', new FocusProbe('second', $seen), static fn (object $app, Interactive $w): object => $app),
        );

        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('second')));
        $tree->render(new Frame(10, 2), new Rect(0, 0, 10, 2), $pass->context);

        self::assertSame(['first' => false, 'second' => true], $seen->getArrayCopy());
    }
}

final readonly class FocusProbe implements Interactive
{
    /**
     * @param ArrayObject<string, bool> $seen
     */
    public function __construct(private string $name, private ArrayObject $seen)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->seen[$this->name] = $context->hasFocus();
    }
}
