<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;

/**
 * @internal the app a WidgetHarness hosts its widget in
 *
 * @template TWidget of Interactive
 */
final readonly class WidgetHost extends App
{
    /**
     * @param TWidget $widget
     */
    public function __construct(public Interactive $widget)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'widget',
            $this->widget,
            static fn (self $host, Interactive $widget): self => new self($widget),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focusFirst();
    }

    /**
     * What no component is the address of, such as an effect's result, is the
     * hosted widget's: there is nobody else for it.
     */
    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $this->widget->update($message, $screen)
            ->mapModel(fn (Interactive $widget): self => $widget === $this->widget ? $this : new self($widget));
    }
}
