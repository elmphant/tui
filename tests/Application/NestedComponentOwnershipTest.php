<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Apply child replacements to the mounted sub-model, then store that model back in the App.
 */
final class NestedComponentOwnershipTest extends TestCase
{
    #[Test]
    public function aReplacementReachesTheSubModelThatBoundIt(): void
    {
        $panel = new OwningPanel(TextInput::new()->withValue('before'));
        $app = new HostingApp($panel);

        $bound = Mount::named(
            'panel',
            $panel,
            $panel->field,
            static fn (HostingApp $host, OwningPanel $owner): HostingApp => $host->withPanel($owner),
        )->own(Component::bind(
            'field',
            $panel->field,
            static fn (OwningPanel $owner, TextInput $field): OwningPanel => $owner->withField($field),
        ));

        $updated = $bound->replaceIn($app, TextInput::new()->withValue('after'), new KeyMsg('x'))->model;

        self::assertInstanceOf(HostingApp::class, $updated);
        self::assertSame('after', $updated->panel->field->value());
    }
}

final readonly class HostingApp extends App
{
    public function __construct(public OwningPanel $panel)
    {
    }

    public function withPanel(OwningPanel $panel): self
    {
        return new self($panel);
    }

    public function view(): Widget
    {
        return $this->panel->field;
    }
}

final readonly class OwningPanel implements Reducer
{
    public function __construct(public TextInput $field)
    {
    }

    public function withField(TextInput $field): self
    {
        return new self($field);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }
}
