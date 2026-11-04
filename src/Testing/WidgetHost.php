<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
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
     * Send unaddressed messages, including effect results, to the only hosted widget.
     */
    protected function onMessage(Message $message, InputContext $input): UpdateResult
    {
        return $this->widget->update($message, $input->screen())
            ->mapModel(fn (Interactive $widget): self => $widget === $this->widget ? $this : new self($widget));
    }
}
