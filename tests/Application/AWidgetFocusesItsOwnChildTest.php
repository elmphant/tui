<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AWidgetFocusesItsOwnChildTest extends TestCase
{
    #[Test]
    public function theKeyboardGoesToTheChildTheWidgetNames(): void
    {
        $harness = ModelHarness::for(JumpingPairApp::new(), width: 30, height: 4)->init();
        self::assertSame('pair/0', $harness->focused()?->value);

        $harness->press('ctrl+j');

        self::assertSame('pair/1', $harness->focused()?->value);
    }

    #[Test]
    public function insideAMountTheChildIsTheMountsOwn(): void
    {
        $harness = ModelHarness::for(new HostOfAJumpingPair(JumpingPairApp::new()), width: 30, height: 4)->init();
        self::assertSame('inner/pair/0', $harness->focused()?->value);

        $harness->press('ctrl+j');

        self::assertSame('inner/pair/1', $harness->focused()?->value);
    }

    #[Test]
    public function aChildFocusNoWidgetSentIsRefused(): void
    {
        $harness = ModelHarness::for(JumpingPairApp::new(), width: 30, height: 4)->init();

        $harness->press('ctrl+g');

        self::assertSame('pair/0', $harness->focused()?->value);
    }
}

final readonly class JumpingPair implements Interactive, GraftsItsChildren
{
    /**
     * @param list<TextInput> $fields
     */
    public function __construct(private array $fields)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(2));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $message instanceof KeyMsg && $message->ctrl && $message->key === 'j' ? Cmd::focusChild(1) : null);
    }

    public function children(): iterable
    {
        return $this->fields;
    }

    public function placedChildren(Rect $area): iterable
    {
        return array_map(static fn (TextInput $field, int $row): array => [$field, new Rect($area->x, $area->y + $row, $area->width, 1)], $this->fields, array_keys($this->fields));
    }

    public function withChildAt(int $index, Widget $child): static
    {
        $fields = $this->fields;
        $fields[$index] = $child instanceof TextInput ? $child : $fields[$index];

        return new static($fields);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($this->placedChildren($area) as $index => [$field, $rect]) {
            $context->drawChild($index, $field, $frame, $rect);
        }
    }
}

final readonly class JumpingPairApp extends App
{
    public function __construct(public JumpingPair $pair)
    {
    }

    public static function new(): self
    {
        return new self(new JumpingPair([TextInput::new(), TextInput::new()]));
    }

    public function view(): Widget
    {
        return $this->bind('pair');
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('stray', ['ctrl+g'], static fn (self $app): UpdateResult => new UpdateResult($app, Cmd::focusChild(1)));
    }
}

final readonly class HostOfAJumpingPair extends App
{
    public function __construct(public JumpingPairApp $inner)
    {
    }

    public function view(): Widget
    {
        return $this->mount('inner', $this->inner->view());
    }
}
