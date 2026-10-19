<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterBar;
use Elmphant\Tui\Widget\Filter\SearchMode;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchModeTest extends TestCase
{
    #[Test]
    public function aWidgetWithAFieldOfItsOwnTypesOnlyAfterSlashInVimMode(): void
    {
        $harness = ModelHarness::for(new OwnFieldApp(OwnFilter::new()), 30, 3)->initialize()->type('jk');

        self::assertSame('', $harness->model()->filter->query());
        self::assertSame(['/' => 'search'], $harness->focusedHints());

        $harness->press('/')->type('jk');

        self::assertSame('jk', $harness->model()->filter->query());
        self::assertSame(['Esc/Enter' => 'back'], $harness->focusedHints());
    }
}

final readonly class OwnFilter implements Focusable, KeybindingsAware, PublishesHints, TakesText
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    use SearchMode;

    private function __construct(
        private TextInput $field,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $searching = false,
    ) {
    }

    public static function new(): self
    {
        return new self(FilterBar::field(''));
    }

    public static function defaultKeybindings(): Keybindings
    {
        return FilterBar::keybindings();
    }

    public function query(): string
    {
        return $this->field->value();
    }

    public function hints(): array
    {
        return $this->searchHints([]);
    }

    public function sizing(): Sizing
    {
        return $this->field->sizing();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        $toggled = $this->searchToggledBy($message);
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        return new UpdateResult($this->takesText() ? $this->cloneWith(field: $this->field->update($message, $area)->model) : $this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->field->render($frame, $area, $context);
    }
}

final readonly class OwnFieldApp extends CopyableTestApp
{
    public function __construct(public OwnFilter $filter)
    {
    }

    public function view(): Widget
    {
        return Component::bind('filter', $this->filter, static fn (self $app, OwnFilter $filter): self => new self($filter));
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}
