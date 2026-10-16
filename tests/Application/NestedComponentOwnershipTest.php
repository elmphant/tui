<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sub-model embedded in an App binds its own children: the replacement runs
 * against the sub-model, and the sub-model is put back into the App. Without
 * this a nested widget can only be handed the root, which it cannot rebuild.
 */
final class NestedComponentOwnershipTest extends TestCase
{
    #[Test]
    public function aReplacementReachesTheSubModelThatBoundIt(): void
    {
        $panel = new OwningPanel(TextInput::new()->withValue('before'));
        $app = new HostingApp($panel);

        $bound = Component::bind(
            'field',
            $panel->field,
            static fn (OwningPanel $owner, TextInput $field): OwningPanel => $owner->withField($field),
        )->nestedIn(
            $panel,
            static fn (HostingApp $host, OwningPanel $owner): HostingApp => $host->withPanel($owner),
        );

        $updated = $bound->replaceIn($app, TextInput::new()->withValue('after'));

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
