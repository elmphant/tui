<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\FocusScope;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Taking the scopes off the ring assumes there is content beside the chrome. A
 * tree that is all scope has none, and an empty ring is a keyboard with nowhere
 * to land: no component ever focuses and every key falls through.
 */
final class AScopedRingAlwaysHasSomewhereToGoTest extends TestCase
{
    #[Test]
    public function aTreeThatIsNothingButAScopeStillOffersItsControls(): void
    {
        self::assertNotSame(
            [],
            (new NothingButAScopeApp())->focusableIds(),
            'the scope is all there is, so it is where the keyboard goes',
        );
    }
}

final readonly class NothingButAScopeApp extends App
{
    public function __construct(
        public TextInput $only = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return new OnlyScope(Component::bind(
            'only',
            $this->only,
            static fn (self $app, TextInput $field): self => $app->cloneWith(only: $field),
        ));
    }
}

final readonly class OnlyScope implements Widget, WidgetContainer, FocusScope
{
    public function __construct(private Widget $content)
    {
    }

    public function sizing(): Sizing
    {
        return $this->content->sizing();
    }

    public function children(): iterable
    {
        return [$this->content];
    }

    public function childWidgets(Rect $area): iterable
    {
        return [[$this->content, $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->content->render($frame, $area, $context);
    }
}
