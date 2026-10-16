<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use ArrayObject;
use Elmphant\Tui\Application\App;
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
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The defect this plan was written for: an app that flags two bound children
 * drew both focused while only one received keys.
 */
final class TwoFlaggedChildrenTest extends TestCase
{
    #[Test]
    public function onlyTheComponentTheTreeNamesDrawsFocused(): void
    {
        /** @var ArrayObject<string, bool> $drawn */
        $drawn = new ArrayObject();

        $app = new TwoFlaggedApp(
            new FlaggedField('first', $drawn, true),
            new FlaggedField('second', $drawn, true),
        );

        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(new FocusState(ComponentId::named('second')));
        $app->render(new Frame(20, 4), $pass);

        self::assertSame(['first' => false, 'second' => true], $drawn->getArrayCopy());
    }
}

final readonly class TwoFlaggedApp extends App
{
    public function __construct(
        public FlaggedField $first = new FlaggedField('first', new ArrayObject()),
        public FlaggedField $second = new FlaggedField('second', new ArrayObject()),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, FlaggedField $w): self => $app->cloneWith(first: $w)),
            Component::bind('second', $this->second, static fn (self $app, FlaggedField $w): self => $app->cloneWith(second: $w)),
        );
    }
}

final readonly class FlaggedField implements Focusable
{
    use DrawsFocus;

    /**
     * @param ArrayObject<string, bool> $drawn
     */
    public function __construct(
        private string $name,
        private ArrayObject $drawn,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->name, $this->drawn, $focused);
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
        $this->drawn[$this->name] = $this->drawsFocused($context);
    }
}
