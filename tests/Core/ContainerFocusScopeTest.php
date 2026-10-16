<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Interactive;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContainerFocusScopeTest extends TestCase
{
    /**
     * A container owns the focus for its whole subtree, but only one part of it
     * shows a caret — the field, not the list beside it.
     */
    #[Test]
    public function aContainerCanWithholdFocusFromOneOfItsParts(): void
    {
        /** @var ArrayObject<string, bool> $seen */
        $seen = new ArrayObject();

        $searchBox = new FieldBesideList(
            new ScopeProbe('field', $seen),
            new ScopeProbe('list', $seen),
        );

        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(new FocusState(ComponentId::named('search')));

        Component::bind('search', $searchBox, static fn (object $app, Interactive $w): object => $app)
            ->render(new Frame(10, 2), new Rect(0, 0, 10, 2), $pass->context);

        self::assertSame(['field' => true, 'list' => false], $seen->getArrayCopy());
    }
}

final readonly class FieldBesideList implements Interactive
{
    public function __construct(private ScopeProbe $field, private ScopeProbe $list)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(2));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->field->render($frame, $area, $context);

        $context->withoutFocus(function () use ($frame, $area, $context): void {
            $this->list->render($frame, $area, $context);
        });
    }
}

final readonly class ScopeProbe implements Interactive
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
